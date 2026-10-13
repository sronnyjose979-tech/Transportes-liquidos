<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use App\Modules\Workshop\Models\WorkshopAuthorizationApplication;
use App\Modules\Workshop\Models\WorkshopAuthorizationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Availability commands share the existing Fleet/Transport write coordinator. */
final class WorkshopAvailability
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    private function snapshot(string $table, string $id): array
    {
        $row = DB::selectOne("SELECT to_jsonb(t)::text AS data FROM {$table} t WHERE id=? FOR UPDATE", [$id]);
        if ($row === null) {
            throw ValidationException::withMessages(['resource' => 'Recurso inexistente.']);
        }

        return json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
    }

    private function project(string $asset): void
    {
        DB::update('UPDATE fleet_assets SET service_status=workshop_asset_projection(id) WHERE id=?', [$asset]);
    }

    public function admit(User $actor, string $assetId, string $key, array $data): array
    {
        return $this->commands->run($actor, 'admission_create:'.$assetId, $key, $data,
            fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator', 'secretary']),
            function (User $fresh, string $operationId) use ($assetId, $data): array {
                $this->snapshot('fleet_assets', $assetId);
                $admission = (string) Str::uuid();
                $impediment = (string) Str::uuid();
                DB::table('workshop_admissions')->insert(['id' => $admission, 'fleet_asset_id' => $assetId, 'kind' => $data['kind'], 'admitted_at' => $data['admitted_at'], 'admitted_by_id' => $fresh->id, 'reason' => $data['reason']]);
                DB::table('fleet_operating_impediments')->insert(['id' => $impediment, 'fleet_asset_id' => $assetId, 'kind' => 'workshop', 'admission_id' => $admission, 'effect' => 'maintenance', 'description' => $data['reason'], 'actor_id' => $fresh->id, 'occurred_at' => $data['admitted_at'], 'reason' => $data['reason']]);
                $warnings = [];
                foreach (DB::table('trip_asset_assignments as a')->where('a.fleet_asset_id', $assetId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('trip_asset_releases as r')->whereColumn('r.assignment_id', 'a.id'))->orderBy('a.id')->get() as $assignment) {
                    $warning = (string) Str::uuid();
                    DB::table('workshop_warnings')->insert(['id' => $warning, 'admission_id' => $admission, 'trip_asset_assignment_id' => $assignment->id, 'code' => 'assigned_trip', 'context' => json_encode(['assignment_id' => $assignment->id, 'trip_id' => $assignment->trip_id], JSON_THROW_ON_ERROR), 'captured_by_id' => $fresh->id]);
                    $warnings[] = $warning;
                }
                $this->project($assetId);

                return ['operation' => ['admission_id' => $admission, 'operation_type' => 'admission_create', 'impediment_id' => $impediment, 'after_values' => $this->snapshot('workshop_admissions', $admission), 'reason' => $data['reason'], 'occurred_at' => $data['admitted_at']], 'result' => ['admission_id' => $admission, 'impediment_id' => $impediment, 'warning_ids' => $warnings]];
            });
    }

    public function captureHistorical(User $actor, string $assetId, string $key, string $reason): array
    {
        return $this->commands->run($actor, 'historical_observe:'.$assetId, $key, compact('reason'), fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($assetId, $reason): array {
                $asset = $this->snapshot('fleet_assets', $assetId);
                $id = (string) Str::uuid();
                DB::table('fleet_operating_impediments')->insert(['id' => $id, 'fleet_asset_id' => $assetId, 'kind' => 'historical_observation', 'effect' => $asset['service_status'], 'description' => 'Estado histórico observado; origen desconocido.', 'actor_id' => $fresh->id, 'occurred_at' => now()->toIso8601String(), 'reason' => $reason]);
                $this->project($assetId);

                return ['operation' => ['fleet_asset_id' => $assetId, 'operation_type' => 'historical_observe', 'impediment_id' => $id, 'reason' => $reason, 'after_values' => $this->snapshot('fleet_operating_impediments', $id)], 'result' => ['impediment_id' => $id]];
            });
    }

    public function recordImpediment(User $actor, string $assetId, string $key, array $data): array
    {
        return $this->commands->run($actor, 'impediment_record:'.$assetId, $key, $data, fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator', 'secretary']),
            function (User $fresh, string $operationId) use ($assetId, $data): array {
                $this->snapshot('fleet_assets', $assetId);
                $id = (string) Str::uuid();
                DB::table('fleet_operating_impediments')->insert(['id' => $id, 'fleet_asset_id' => $assetId, 'kind' => 'workshop', 'admission_id' => $data['admission_id'] ?? null, 'work_order_id' => $data['work_order_id'] ?? null, 'effect' => $data['effect'], 'description' => $data['description'], 'actor_id' => $fresh->id, 'occurred_at' => $data['occurred_at'], 'reason' => $data['reason']]);
                $this->project($assetId);

                return ['operation' => ['fleet_asset_id' => $assetId, 'operation_type' => 'impediment_record', 'impediment_id' => $id, 'reason' => $data['reason'], 'after_values' => $this->snapshot('fleet_operating_impediments', $id)], 'result' => ['impediment_id' => $id]];
            });
    }

    public function resolve(User $actor, string $impedimentId, string $key, string $reason, array $evidence = []): array
    {
        return $this->commands->run($actor, 'impediment_resolve:'.$impedimentId, $key, compact('reason', 'evidence'), fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($impedimentId, $reason, $evidence): array {
                $asset = DB::table('fleet_operating_impediments')->where('id', $impedimentId)->value('fleet_asset_id');
                $this->snapshot('fleet_assets', $asset);
                $cause = $this->snapshot('fleet_operating_impediments', $impedimentId);
                $id = (string) Str::uuid();
                DB::table('fleet_impediment_resolutions')->insert(['id' => $id, 'impediment_id' => $impedimentId, 'operation_id' => $operationId, 'resolved_by_id' => $fresh->id, 'resolved_at' => now()->toIso8601String(), 'reason' => $reason, 'evidence' => json_encode((object) $evidence, JSON_THROW_ON_ERROR)]);
                $this->project($asset);

                return ['operation' => ['fleet_asset_id' => $asset, 'operation_type' => $cause['kind'] === 'historical_observation' ? 'historical_resolve' : 'impediment_resolve', 'impediment_id' => $impedimentId, 'reason' => $reason, 'before_values' => $cause, 'after_values' => ['resolution_id' => $id]], 'result' => ['resolution_id' => $id]];
            });
    }

    /** The server constructs the review; the caller only chooses explicit causes. */
    public function requestExit(User $actor, string $admissionId, string $key, array $data): array
    {
        return $this->commands->run($actor, 'authorization_request:'.$admissionId, $key, $data, fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($admissionId, $data): array {
                $asset = DB::table('workshop_admissions')->where('id', $admissionId)->value('fleet_asset_id');
                $this->snapshot('fleet_assets', $asset);
                $before = $this->snapshot('workshop_admissions', $admissionId);
                $review = json_decode(DB::selectOne('SELECT workshop_exit_content(?::uuid)::text AS data', [$admissionId])->data, true, flags: JSON_THROW_ON_ERROR);
                $ids = $data['resolve_ids'];
                sort($ids, SORT_STRING);
                $content = ['review' => $review, 'resolve_ids' => $ids, 'exited_at' => $data['exited_at'], 'after' => array_merge($before, ['version' => $before['version'] + 1])];
                $request = WorkshopAuthorizationRequest::create(['admission_id' => $admissionId, 'type' => 'exit', 'target_version' => $before['version'], 'content' => $content, 'requested_by_id' => $fresh->id, 'reason' => $data['reason'], 'previous_request_id' => $data['previous_request_id'] ?? null]);

                return ['operation' => ['admission_id' => $admissionId, 'operation_type' => 'authorization_request', 'authorization_request_id' => $request->id, 'reason' => $data['reason'], 'after_values' => $content], 'result' => ['request_id' => $request->id]];
            });
    }

    public function exit(User $actor, string $decisionId, string $key): array
    {
        return $this->commands->run($actor, 'admission_exit:'.$decisionId, $key, compact('decisionId'), fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator']),
            function (User $fresh, string $operationId) use ($decisionId): array {
                $request = DB::table('workshop_authorization_requests as q')->join('workshop_authorization_decisions as d', 'd.request_id', '=', 'q.id')->where('d.id', $decisionId)->select('q.*')->first();
                if ($request === null || $request->type !== 'exit') {
                    throw ValidationException::withMessages(['decision' => 'Decisión de salida requerida.']);
                }
                $asset = DB::table('workshop_admissions')->where('id', $request->admission_id)->value('fleet_asset_id');
                $this->snapshot('fleet_assets', $asset);
                $before = $this->snapshot('workshop_admissions', $request->admission_id);
                $content = json_decode($request->content, true, flags: JSON_THROW_ON_ERROR);
                if ($before['version'] !== $request->target_version) {
                    throw ValidationException::withMessages(['version' => 'Ingreso modificado desde revisión.']);
                }
                $application = WorkshopAuthorizationApplication::create(['decision_id' => $decisionId, 'operation_id' => $operationId, 'applied_by_id' => $fresh->id]);
                foreach ($content['resolve_ids'] as $cause) {
                    DB::table('fleet_impediment_resolutions')->insert(['id' => (string) Str::uuid(), 'impediment_id' => $cause, 'operation_id' => $operationId, 'resolved_by_id' => $fresh->id, 'resolved_at' => $content['exited_at'], 'reason' => $request->reason, 'evidence' => json_encode(['authorization_request_id' => $request->id], JSON_THROW_ON_ERROR)]);
                }
                $id = (string) Str::uuid();
                DB::table('workshop_admission_exits')->insert(['id' => $id, 'admission_id' => $request->admission_id, 'authorization_application_id' => $application->id, 'exited_at' => $content['exited_at'], 'recorded_by_id' => $fresh->id, 'reason' => $request->reason, 'reviewed_content' => $request->content]);
                DB::table('workshop_admissions')->where('id', $request->admission_id)->update(['version' => $before['version'] + 1]);
                $this->project($asset);

                return ['operation' => ['admission_id' => $request->admission_id, 'operation_type' => 'admission_exit', 'from_version' => $before['version'], 'to_version' => $before['version'] + 1, 'before_values' => $before, 'after_values' => $this->snapshot('workshop_admissions', $request->admission_id), 'reason' => $request->reason, 'occurred_at' => $content['exited_at']], 'result' => ['exit_id' => $id, 'application_id' => $application->id]];
            });
    }

    public function linkOrder(User $actor, string $orderId, string $admissionId, string $key, string $reason): array
    {
        return $this->commands->run($actor, 'order_admission_link_create:'.$orderId, $key, compact('admissionId', 'reason'), fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator', 'secretary']),
            function (User $fresh, string $operationId) use ($orderId, $admissionId, $reason): array {
                $asset = DB::table('workshop_work_orders')->where('id', $orderId)->value('fleet_asset_id');
                $this->snapshot('fleet_assets', $asset);
                $this->snapshot('workshop_work_orders', $orderId);
                $this->snapshot('workshop_admissions', $admissionId);
                $id = (string) Str::uuid();
                DB::table('workshop_order_admission_links')->insert(['id' => $id, 'work_order_id' => $orderId, 'admission_id' => $admissionId, 'fleet_asset_id' => $asset, 'linked_by_id' => $fresh->id, 'reason' => $reason, 'operation_id' => $operationId]);

                return ['operation' => ['work_order_id' => $orderId, 'operation_type' => 'order_admission_link_create', 'order_admission_link_id' => $id, 'reason' => $reason, 'after_values' => ['link_id' => $id]], 'result' => ['link_id' => $id]];
            });
    }
}
