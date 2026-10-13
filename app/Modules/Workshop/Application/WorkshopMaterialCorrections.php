<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Executes exactly the sensitive historical correction approved by administration. */
final class WorkshopMaterialCorrections
{
    private const TARGETS = [
        'workshop_part_deliveries' => ['delivery', 'delivery_id', 'previous_delivery_id', 'recorded_by_id'],
        'workshop_part_usages' => ['usage', 'usage_id', 'previous_usage_id', 'confirmed_by_id'],
        'workshop_external_repairs' => ['external', 'external_repair_id', 'previous_record_id', 'recorded_by_id'],
        'workshop_material_needs' => ['material_need', 'material_need_id', 'previous_need_id', 'requested_by_id'],
        'workshop_cost_records' => ['cost', 'cost_record_id', 'previous_cost_id', 'actor_id'],
    ];

    public function __construct(private readonly WorkshopCommands $commands) {}

    public function apply(User $actor, string $decisionId, string $key, string $expectedTable): array
    {
        if (! isset(self::TARGETS[$expectedTable])) {
            throw ValidationException::withMessages(['table' => 'Tipo de corrección no permitido.']);
        }

        return $this->commands->run($actor, 'fact_revise:'.$decisionId, $key, compact('decisionId', 'expectedTable'),
            fn (User $fresh) => $this->authorize($fresh, $expectedTable, $decisionId),
            fn (User $fresh, string $operationId) => $this->execute($fresh, $decisionId, $operationId, $expectedTable));
    }

    /** Decisions are ordered explicitly: revised covering costs precede their components. */
    public function applyMany(User $actor, array $decisions, string $key): array
    {
        if ($decisions === [] || count($decisions) !== count(array_unique($decisions)) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['decisions' => 'Decisiones únicas ordenadas y clave UUID requeridas.']);
        }
        foreach ($decisions as $decision) {
            if (! is_string($decision) || ! Str::isUuid($decision)) {
                throw ValidationException::withMessages(['decisions' => 'UUID requerido.']);
            }
        }

        return $this->commands->run($actor, 'facts_revise_batch', $key, compact('decisions'),
            fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($decisions, $key): array {
                $results = [];
                $principal = null;
                $orderId = null;
                foreach ($decisions as $index => $decisionId) {
                    $request = DB::table('workshop_authorization_requests as q')->join('workshop_authorization_decisions as d', 'd.request_id', '=', 'q.id')->where('d.id', $decisionId)->select('q.content', 'q.work_order_id')->first();
                    if ($request === null) {
                        throw ValidationException::withMessages(['decisions' => 'Solicitud inexistente.']);
                    }
                    if ($orderId !== null && $orderId !== $request->work_order_id) {
                        throw ValidationException::withMessages(['decisions' => 'El lote debe pertenecer a una misma orden.']);
                    }
                    $orderId = $request->work_order_id;
                    $table = json_decode($request->content, true, flags: JSON_THROW_ON_ERROR)['fact_table'] ?? '';
                    if (! isset(self::TARGETS[$table])) {
                        throw ValidationException::withMessages(['decisions' => 'Tipo de hecho incompatible.']);
                    }
                    $id = $index === 0 ? $operationId : (string) Uuid::uuid5($key, $decisionId);
                    $command = $this->execute($fresh, $decisionId, $id, $table);
                    $results[$decisionId] = $command['result'];
                    if ($index === 0) {
                        $principal = $command['operation'];
                    } else {
                        $childKey = (string) Uuid::uuid5($key, 'receipt:'.$decisionId);
                        $this->commands->record($fresh, 'fact_revise:'.$decisionId, $childKey, compact('decisionId', 'table'), $command['operation'], $command['result'], $id);
                    }
                }

                return ['operation' => $principal, 'result' => ['revisions' => $results]];
            });
    }

    private function authorize(User $actor, string $table, string $decisionId): void
    {
        $roles = match ($table) {
            'workshop_part_usages' => ['administrator', 'mechanic'],
            'workshop_external_repairs' => ['administrator'],
            'workshop_material_needs' => ['administrator', 'mechanic'],
            default => ['administrator'],
        };
        $this->commands->authorizeRoles($actor, $roles);
        if (! $actor->roles()->where('name', 'administrator')->exists()) {
            $request = DB::table('workshop_authorization_requests as q')->join('workshop_authorization_decisions as d', 'd.request_id', '=', 'q.id')->where('d.id', $decisionId)->select('q.work_order_id')->first();
            if ($request === null || ! DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.work_order_id', $request->work_order_id)->where('e.user_id', $actor->id)->where('e.is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_mechanic_releases as r')->whereColumn('r.assignment_id', 'a.id'))->exists()) {
                throw new AuthorizationException('Participación vigente requerida para rectificar.');
            }
        }
    }

    private function execute(User $fresh, string $decisionId, string $operationId, string $expectedTable): array
    {
        $request = DB::table('workshop_authorization_requests as q')->join('workshop_authorization_decisions as d', 'd.request_id', '=', 'q.id')->where('d.id', $decisionId)->where('d.decision', 'approved')->select('q.*')->first();
        if ($request === null || $request->type !== 'rectification') {
            throw ValidationException::withMessages(['decision' => 'Rectificación aprobada requerida.']);
        }
        $content = json_decode($request->content, true, flags: JSON_THROW_ON_ERROR);
        if (($content['fact_table'] ?? null) !== $expectedTable || ($content['executor_id'] ?? null) != $fresh->id) {
            throw ValidationException::withMessages(['decision' => 'Hecho o ejecutor incompatible con aprobación.']);
        }
        $order = DB::table('workshop_work_orders')->where('id', $request->work_order_id)->lockForUpdate()->firstOrFail();
        if ($order->version !== $request->target_version) {
            throw ValidationException::withMessages(['version' => 'Orden modificada desde revisión.']);
        }
        [$kind, $destination, $previousField, $actorField] = self::TARGETS[$expectedTable];
        $priorJson = DB::selectOne("SELECT to_jsonb(t)::text AS data FROM {$expectedTable} t WHERE id=? FOR UPDATE", [$content['fact_id']])->data;
        $prior = json_decode($priorJson, true, flags: JSON_THROW_ON_ERROR);
        if ($prior != $content['before']) {
            throw ValidationException::withMessages(['before' => 'Snapshot histórico incompatible.']);
        }
        $id = $content['planned_record_id'] ?? (string) Str::uuid();
        $row = array_merge($content['after'], ['id' => $id, $actorField => $fresh->id, 'root_record_id' => $prior['root_record_id'], $previousField => $prior['id'], 'revision' => $prior['revision'] + 1]);
        if (array_key_exists('work_order_id', $prior)) {
            $row['work_order_id'] = $request->work_order_id;
        }
        if ($kind === 'external') {
            DB::table($expectedTable)->insert($row);
        } else {
            $decimalField = $kind === 'cost' ? 'amount' : 'quantity';
            $decimal = DB::selectOne("SELECT content->'after'->>? AS value FROM workshop_authorization_requests WHERE id=?", [$decimalField, $request->id])->value;
            unset($row[$decimalField]);
            DB::select('SELECT workshop_write_'.$kind.'(?::jsonb, ?::text)', [json_encode($row, JSON_THROW_ON_ERROR), $decimal]);
        }
        DB::table('workshop_authorization_applications')->insert(['id' => (string) Str::uuid(), 'decision_id' => $decisionId, 'operation_id' => $operationId, 'applied_by_id' => $fresh->id]);
        $snapshotJson = DB::selectOne("SELECT to_jsonb(t)::text AS data FROM {$expectedTable} t WHERE id=?", [$id])->data;
        $snapshot = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
        $type = $kind === 'cost' && $prior['cost_kind'] === null && $snapshot['cost_kind'] !== null ? 'cost_classify' : $kind.'_revise';

        return ['operation' => ['work_order_id' => $request->work_order_id, 'operation_type' => $type, $destination => $id, 'before_values' => $priorJson, 'after_values' => $snapshotJson, 'reason' => $row['reason'], 'occurred_at' => $row['occurred_at'] ?? $row['performed_at']], 'result' => [$destination => $id]];
    }
}
