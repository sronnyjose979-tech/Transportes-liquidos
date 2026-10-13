<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use App\Modules\Workshop\Models\WorkshopAuthorizationApplication;
use App\Modules\Workshop\Models\WorkshopAuthorizationDecision;
use App\Modules\Workshop\Models\WorkshopAuthorizationRequest;
use App\Modules\Workshop\Models\WorkshopScope;
use App\Modules\Workshop\Models\WorkshopScopeTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkshopAuthorizations
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    private function permit(User $actor, array $roles): void
    {
        if (! $actor->is_active || ! $actor->roles()->whereIn('name', $roles)->exists()) {
            throw new AuthorizationException('Operación de Taller no autorizada.');
        }
    }

    private function snapshot(string $order): array
    {
        return json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=? FOR UPDATE', [$order])->data, true);
    }

    /** Creates the complete immutable relational snapshot and its request in one transaction. */
    public function requestScope(User $actor, string $orderId, string $key, array $data): array
    {
        foreach ($data['parts'] ?? [] as $index => $part) {
            $quantity = $part['quantity'] ?? null;
            if (! is_string($quantity) || strlen($quantity) > 4096 || ! preg_match('/^\+?[0-9]+(?:\.[0-9]{1,6})?$/D', $quantity)) {
                throw ValidationException::withMessages(['quantity' => 'Cantidad positiva como texto decimal exacto, máximo seis decimales.']);
            }
            [$whole,$fraction] = array_pad(explode('.', ltrim($quantity, '+'), 2), 2, '');
            $whole = ltrim($whole, '0');
            $fraction = rtrim($fraction, '0');
            $data['parts'][$index]['quantity'] = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);
        }

        return $this->commands->run($actor, 'scope_request:'.$orderId, $key, $data,
            function (User $fresh) use ($orderId): void {
                $this->permit($fresh, ['administrator', 'secretary', 'mechanic']);
                if (! $fresh->roles()->whereIn('name', ['administrator', 'secretary'])->exists() && ! DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.work_order_id', $orderId)->where('e.user_id', $fresh->id)->where('e.is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_mechanic_releases as r')->whereColumn('r.assignment_id', 'a.id'))->exists()) {
                    throw new AuthorizationException('Participación vigente requerida.');
                }
            }, function (User $fresh, string $operationId) use ($orderId, $data): array {
                $before = $this->snapshot($orderId);
                $scopeId = (string) Str::uuid();
                $previous = isset($data['previous_scope_id']) ? WorkshopScope::findOrFail($data['previous_scope_id']) : null;
                $kind = $data['scope_kind'] ?? 'initial';
                $scope = WorkshopScope::forceCreate(['id' => $scopeId, 'work_order_id' => $orderId, 'diagnosis_id' => $data['diagnosis_id'], 'scope_kind' => $kind,
                    'proposed_by_id' => $fresh->id, 'description' => $data['description'], 'root_record_id' => $previous?->root_record_id ?? $scopeId,
                    'previous_scope_id' => $previous?->id, 'revision' => $previous ? $previous->revision + 1 : 1]);
                $tasks = [];
                foreach ($data['tasks'] as $line) {
                    $task = WorkshopScopeTask::create(['scope_id' => $scope->id, 'line_number' => $line['line_number'], 'description' => $line['description'], 'supplier_id' => $line['supplier_id'] ?? null]);
                    $tasks[$line['line_number']] = $task->id;
                }
                foreach ($data['parts'] ?? [] as $line) {
                    if (! is_string($line['quantity'])) {
                        throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser texto decimal exacto.']);
                    }
                    DB::select('SELECT workshop_write_scope_part(?::uuid,?::uuid,?::uuid,?::uuid,?::integer,?::text)', [(string) Str::uuid(), $scopeId, isset($line['task_line_number']) ? $tasks[$line['task_line_number']] : null, $line['spare_part_id'], $line['line_number'], $line['quantity']]);
                }
                $content = json_decode(DB::selectOne('SELECT workshop_scope_content(?::uuid)::text AS data', [$scopeId])->data, true);
                $request = WorkshopAuthorizationRequest::create(['work_order_id' => $orderId, 'scope_id' => $scopeId,
                    'type' => match ($kind) {
                        'initial' => 'repair','extension' => 'extension','revision' => 'rectification'
                    }, 'content' => $content,
                    'target_version' => $kind === 'revision' ? $before['version'] : null, 'requested_by_id' => $fresh->id,
                    'previous_request_id' => $data['previous_request_id'] ?? null, 'reason' => $data['reason']]);
                $operation = ['work_order_id' => $orderId, 'operation_type' => 'scope_request', 'scope_id' => $scopeId, 'authorization_request_id' => $request->id,
                    'reason' => $data['reason'], 'occurred_at' => now()->toIso8601String(), 'after_values' => $content];
                if ($kind === 'initial' && $before['status'] === 'pending_diagnosis') {
                    DB::table('workshop_work_orders')->where('id', $orderId)->update(['status' => 'pending_authorization', 'version' => $before['version'] + 1]);
                    $operation += ['from_version' => $before['version'], 'to_version' => $before['version'] + 1, 'before_values' => $before];
                    $operation['after_values'] = $this->snapshot($orderId);
                }

                return ['operation' => $operation, 'result' => ['scope_id' => $scopeId, 'request_id' => $request->id]];
            });
    }

    public function request(User $actor, string $key, array $data): array
    {
        $root = $data['work_order_id'] ?? $data['admission_id'];

        return $this->commands->run($actor, 'authorization_request:'.$root, $key, $data,
            fn (User $fresh) => $this->permit($fresh, $data['type'] === 'exit' ? ['administrator'] : ['administrator', 'secretary']),
            function (User $fresh, string $operationId) use ($data): array {
                $request = WorkshopAuthorizationRequest::create(array_merge($data, ['requested_by_id' => $fresh->id]));

                return ['operation' => ['work_order_id' => $request->work_order_id, 'admission_id' => $request->admission_id, 'operation_type' => 'authorization_request',
                    'authorization_request_id' => $request->id, 'scope_id' => $request->scope_id, 'reason' => $request->reason, 'occurred_at' => now()->toIso8601String(), 'after_values' => $request->content],
                    'result' => ['request_id' => $request->id]];
            });
    }

    public function decide(User $actor, string $requestId, string $key, string $decision, string $reason): array
    {
        return $this->commands->run($actor, 'authorization_decide:'.$requestId, $key, compact('requestId', 'decision', 'reason'), fn (User $fresh) => $this->permit($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($requestId, $decision, $reason): array {
                $request = WorkshopAuthorizationRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
                $record = WorkshopAuthorizationDecision::create(['request_id' => $requestId, 'decision' => $decision, 'decided_by_id' => $fresh->id, 'reason' => $reason]);

                return ['operation' => ['work_order_id' => $request->work_order_id, 'admission_id' => $request->admission_id, 'operation_type' => 'authorization_decide', 'scope_id' => $request->scope_id,
                    'authorization_request_id' => $requestId, 'authorization_decision_id' => $record->id, 'reason' => $reason, 'occurred_at' => now()->toIso8601String(), 'after_values' => ['decision' => $decision]], 'result' => ['decision_id' => $record->id]];
            });
    }

    public function applyScope(User $actor, string $decisionId, string $key): array
    {
        return $this->commands->run($actor, 'scope_apply:'.$decisionId, $key, compact('decisionId'), fn (User $fresh) => $this->permit($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($decisionId): array {
                $decision = WorkshopAuthorizationDecision::whereKey($decisionId)->lockForUpdate()->firstOrFail();
                $request = $decision->request;
                if (! $request->scope_id) {
                    throw ValidationException::withMessages(['decision' => 'La decisión no corresponde a un alcance.']);
                }
                $before = $this->snapshot($request->work_order_id);
                if ($request->type === 'rectification' && $before['version'] !== $request->target_version) {
                    throw ValidationException::withMessages(['version' => 'Versión obsoleta.']);
                }
                $operation = ['work_order_id' => $request->work_order_id, 'operation_type' => 'scope_apply', 'scope_id' => $request->scope_id,
                    'reason' => $request->reason, 'occurred_at' => now()->toIso8601String(), 'after_values' => $request->content];
                if ($request->type === 'repair' && $before['status'] === 'pending_authorization') {
                    DB::table('workshop_work_orders')->where('id', $request->work_order_id)->update(['status' => 'authorized', 'version' => $before['version'] + 1]);
                    $operation += ['from_version' => $before['version'], 'to_version' => $before['version'] + 1, 'before_values' => $before];
                    $operation['after_values'] = $this->snapshot($request->work_order_id);
                }
                $application = WorkshopAuthorizationApplication::create(['decision_id' => $decisionId, 'operation_id' => $operationId, 'applied_by_id' => $fresh->id]);

                return ['operation' => $operation, 'result' => ['application_id' => $application->id, 'scope_id' => $request->scope_id]];
            });
    }

    public function applyOrderDecision(User $actor, string $decisionId, string $key): array
    {
        return $this->commands->run($actor, 'order_authorization_apply:'.$decisionId, $key, compact('decisionId'),
            fn (User $fresh) => $this->permit($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($decisionId): array {
                $decision = WorkshopAuthorizationDecision::whereKey($decisionId)->lockForUpdate()->firstOrFail();
                $request = $decision->request;
                if (! $request->work_order_id || $request->scope_id || ! in_array($request->type, ['cancellation', 'reopening', 'rectification'], true)) {
                    throw ValidationException::withMessages(['decision' => 'La decisión no modifica una orden.']);
                }
                $before = $this->snapshot($request->work_order_id);
                if ($before['version'] !== $request->target_version || ($request->content['before'] ?? null) != $before) {
                    throw ValidationException::withMessages(['version' => 'La orden difiere del contenido aprobado.']);
                }
                $after = $request->content['after'] ?? [];
                $status = $after['status'] ?? null;
                if ($request->type === 'cancellation' && (! in_array($before['status'], ['pending_diagnosis', 'pending_authorization', 'authorized', 'in_repair'], true) || $status !== 'cancelled')) {
                    throw ValidationException::withMessages(['status' => 'Cancelación incompatible.']);
                }
                if ($request->type === 'reopening') {
                    if (! in_array($before['status'], ['finished', 'closed', 'cancelled'], true)) {
                        throw ValidationException::withMessages(['status' => 'La orden no está terminada.']);
                    }
                    $hasDiagnosis = DB::table('workshop_diagnoses')->where('work_order_id', $request->work_order_id)->exists();
                    $hasScope = DB::table('workshop_authorization_applications as a')->join('workshop_authorization_decisions as d', 'd.id', '=', 'a.decision_id')->join('workshop_authorization_requests as q', 'q.id', '=', 'd.request_id')->where('q.work_order_id', $request->work_order_id)->whereNotNull('q.scope_id')->exists();
                    $destination = $hasScope ? 'authorized' : ($hasDiagnosis ? 'pending_authorization' : 'pending_diagnosis');
                    if ($status !== $destination) {
                        throw ValidationException::withMessages(['status' => 'Destino de reapertura incompatible con sus hechos.']);
                    }
                }
                DB::table('workshop_work_orders')->where('id', $request->work_order_id)->update([
                    'description' => $after['description'] ?? $before['description'], 'priority' => $after['priority'] ?? $before['priority'],
                    'status' => $status, 'version' => $before['version'] + 1,
                ]);
                $application = WorkshopAuthorizationApplication::create(['decision_id' => $decisionId, 'operation_id' => $operationId, 'applied_by_id' => $fresh->id]);

                return ['operation' => ['work_order_id' => $request->work_order_id, 'operation_type' => match ($request->type) {
                    'cancellation' => 'order_cancel','reopening' => 'order_reopen',default => 'order_update'
                },
                    'from_version' => $before['version'], 'to_version' => $before['version'] + 1, 'before_values' => $before, 'after_values' => $this->snapshot($request->work_order_id),
                    'reason' => $request->reason, 'occurred_at' => now()->toIso8601String()], 'result' => ['application_id' => $application->id, 'work_order_id' => $request->work_order_id]];
            });
    }
}
