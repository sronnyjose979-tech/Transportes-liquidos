<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Models\WorkshopAuthorizationRequest;
use App\Modules\Workshop\Models\WorkshopScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopAuthorizationTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function operation(string $type, string $order, int $actor, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('workshop_operations')->insert($extra + ['id' => $id, 'work_order_id' => $order, 'operation_type' => $type,
            'actor_id' => $actor, 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Prueba', 'after_values' => '{}',
            'request_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'idempotency_scope' => $type, 'confirmed_result' => '{}']);

        return $id;
    }

    private function scope(string $kind = 'initial', array $extra = []): array
    {
        $admin = $this->actor();
        $asset = FleetAsset::create(['code' => 'AUTH-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => '2026-10-12T12:00:00Z', 'created_by_id' => $admin->id]);
        $this->operation('order_create', $order, $admin->id);
        $diagnosis = (string) Str::uuid();
        DB::table('workshop_diagnoses')->insert(['id' => $diagnosis, 'work_order_id' => $order, 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar', 'actor_id' => $admin->id, 'occurred_at' => '2026-10-12T12:00:00Z', 'reason' => 'Prueba', 'root_record_id' => $diagnosis, 'revision' => 1]);
        $this->operation('diagnosis_record', $order, $admin->id, ['diagnosis_id' => $diagnosis]);
        $scope = (string) Str::uuid();
        DB::table('workshop_scopes')->insert($extra + ['id' => $scope, 'work_order_id' => $order, 'diagnosis_id' => $diagnosis, 'scope_kind' => $kind, 'description' => 'Alcance', 'proposed_by_id' => $admin->id, 'root_record_id' => $scope, 'revision' => 1]);
        $task = (string) Str::uuid();
        DB::table('workshop_scope_tasks')->insert(['id' => $task, 'scope_id' => $scope, 'description' => 'Inspección', 'line_number' => 1]);

        return compact('admin', 'order', 'scope', 'task');
    }

    private function requestScope(array $fixture, array $extra = []): string
    {
        $request = (string) Str::uuid();
        $content = DB::selectOne('SELECT workshop_scope_content(?::uuid)::text AS data', [$fixture['scope']])->data;
        DB::table('workshop_authorization_requests')->insert($extra + ['id' => $request, 'work_order_id' => $fixture['order'], 'scope_id' => $fixture['scope'], 'type' => 'repair', 'content' => $content, 'requested_by_id' => $fixture['admin']->id, 'reason' => 'Prueba']);
        $operation = ['scope_id' => $fixture['scope'], 'authorization_request_id' => $request];
        if (($extra['type'] ?? 'repair') === 'repair') {
            $before = DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$fixture['order']])->data;
            $version = DB::table('workshop_work_orders')->where('id', $fixture['order'])->value('version');
            DB::table('workshop_work_orders')->where('id', $fixture['order'])->update(['status' => 'pending_authorization', 'version' => $version + 1]);
            $after = DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$fixture['order']])->data;
            $operation += ['from_version' => $version, 'to_version' => $version + 1, 'before_values' => $before, 'after_values' => $after];
        }
        $this->operation('scope_request', $fixture['order'], $fixture['admin']->id, $operation);

        return $request;
    }

    private function decision(array $fixture, string $request, string $value = 'approved', ?int $actor = null): string
    {
        $id = (string) Str::uuid();
        $actor ??= $fixture['admin']->id;
        DB::table('workshop_authorization_decisions')->insert(['id' => $id, 'request_id' => $request, 'decision' => $value, 'decided_by_id' => $actor, 'reason' => 'Prueba']);
        $this->operation('authorization_decide', $fixture['order'], $actor, ['scope_id' => $fixture['scope'], 'authorization_request_id' => $request, 'authorization_decision_id' => $id]);

        return $id;
    }

    private function apply(array $fixture, string $decision): string
    {
        $operation = ['scope_id' => $fixture['scope']];
        $before = DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$fixture['order']])->data;
        $version = DB::table('workshop_work_orders')->where('id', $fixture['order'])->value('version');
        DB::table('workshop_work_orders')->where('id', $fixture['order'])->update(['status' => 'authorized', 'version' => $version + 1]);
        $after = DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$fixture['order']])->data;
        $operation += ['from_version' => $version, 'to_version' => $version + 1, 'before_values' => $before, 'after_values' => $after];
        $op = $this->operation('scope_apply', $fixture['order'], $fixture['admin']->id, $operation);
        $id = (string) Str::uuid();
        DB::table('workshop_authorization_applications')->insert(['id' => $id, 'decision_id' => $decision, 'operation_id' => $op, 'applied_by_id' => $fixture['admin']->id]);

        return $id;
    }

    public function test_complete_scope_request_decision_application_and_relations(): void
    {
        $f = $this->scope();
        $part = SparePart::create(['code' => 'PART-'.Str::uuid(), 'description' => 'Junta', 'unit' => 'unidad']);
        DB::select('SELECT workshop_write_scope_part(?::uuid,?::uuid,?::uuid,?::uuid,1,?::text)', [(string) Str::uuid(), $f['scope'], $f['task'], $part->id, '1.234567']);
        $request = $this->requestScope($f);
        $decision = $this->decision($f, $request);
        $this->apply($f, $decision);
        $this->checkConstraints();
        $model = WorkshopAuthorizationRequest::findOrFail($request);
        $this->assertSame($f['order'], $model->workOrder->id);
        $this->assertSame($decision, $model->decision->id);
        $this->assertNotNull($model->decision->application);
        $this->assertSame('1.234567', WorkshopScope::findOrFail($f['scope'])->parts->first()->quantity);
    }

    public function test_decimal_text_rejects_rounding_nonfinite_and_alternative_notation(): void
    {
        foreach (['1.0000000', 'NaN', 'Infinity', '1e2', '1,2', '-1', '0', '1000000000000'] as $quantity) {
            $this->rejected(fn () => DB::select('SELECT workshop_exact_quantity(?::text)', [$quantity]));
        }
        $this->assertSame('1.234567', DB::selectOne('SELECT workshop_exact_quantity(?::text)::text AS quantity', ['+0001.234567'])->quantity);
    }

    public function test_snapshot_cannot_be_extended_or_changed_after_request(): void
    {
        $f = $this->scope();
        $this->requestScope($f);
        $this->checkConstraints();
        $this->rejected(fn () => DB::table('workshop_scope_tasks')->insert(['id' => (string) Str::uuid(), 'scope_id' => $f['scope'], 'description' => 'Extra', 'line_number' => 2]));
        $this->rejected(fn () => DB::table('workshop_scope_tasks')->where('id', $f['task'])->update(['description' => 'Otra']));
        $this->rejected(fn () => DB::table('workshop_scopes')->where('id', $f['scope'])->delete());
    }

    public function test_decisions_and_applications_cannot_be_duplicated_or_reused(): void
    {
        $f = $this->scope();
        $r = $this->requestScope($f);
        $d = $this->decision($f, $r);
        $this->apply($f, $d);
        $this->checkConstraints();
        $this->rejected(fn () => $this->decision($f, $r), ['23505', '23514']);
        $this->rejected(fn () => $this->apply($f, $d), ['23505', '23514']);
        $this->rejected(fn () => DB::table('workshop_authorization_decisions')->where('id', $d)->update(['decision' => 'rejected']));
        $this->assertSame(1, DB::table('workshop_authorization_applications')->count());
    }

    public function test_rejection_cannot_be_applied_and_secretary_cannot_decide(): void
    {
        $f = $this->scope();
        $r = $this->requestScope($f);
        $d = $this->decision($f, $r, 'rejected');
        $this->checkConstraints();
        $this->rejected(fn () => $this->apply($f, $d));
        $g = $this->scope();
        $q = $this->requestScope($g);
        $this->checkConstraints();
        $secretary = $this->actor('secretary');
        $this->rejected(fn () => $this->decision($g, $q, 'approved', $secretary->id));
    }

    public function test_requests_reject_wrong_snapshot_type_and_duplicate_request(): void
    {
        $f = $this->scope();
        $this->requestScope($f);
        $this->checkConstraints();
        $this->rejected(fn () => $this->requestScope($f));
        $this->rejected(fn () => $this->requestScope($f, ['content' => '{}']));
        $this->rejected(fn () => $this->requestScope($f, ['type' => 'extension']));
    }

    public function test_services_apply_once_and_return_original_receipts_after_version_changes(): void
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $fixture = $this->scope();
        $this->requestScope($fixture);
        $this->checkConstraints();
        DB::commit();
        $service = app(WorkshopAuthorizations::class);
        $diagnosis = DB::table('workshop_diagnoses')->where('work_order_id', $fixture['order'])->value('id');
        $data = ['scope_kind' => 'extension', 'diagnosis_id' => $diagnosis, 'description' => 'Ampliación', 'tasks' => [['line_number' => 1, 'description' => 'Otro trabajo']], 'reason' => 'Alcance adicional'];
        $catalogPart = SparePart::create(['code' => 'CANON-'.Str::uuid(), 'description' => 'Junta', 'unit' => 'unidad']);
        $data['parts'] = [['line_number' => 1, 'task_line_number' => 1, 'spare_part_id' => $catalogPart->id, 'quantity' => '+0001.200000']];
        $key = (string) Str::uuid();
        $requested = $service->requestScope($fixture['admin'], $fixture['order'], $key, $data);
        $data['parts'][0]['quantity'] = '1.2';
        $this->assertSame($requested, $service->requestScope($fixture['admin'], $fixture['order'], $key, $data));
        $decided = $service->decide($fixture['admin'], $requested['request_id'], (string) Str::uuid(), 'approved', 'Aprobado');
        $applyKey = (string) Str::uuid();
        $applied = $service->applyScope($fixture['admin'], $decided['decision_id'], $applyKey);
        $this->assertSame($applied, $service->applyScope($fixture['admin'], $decided['decision_id'], $applyKey));
        $this->assertSame(1, DB::table('workshop_authorization_applications')->count());
        $this->assertSame('pending_authorization', DB::table('workshop_work_orders')->where('id', $fixture['order'])->value('status'));
    }

    public function test_initial_scope_service_transitions_order_and_rejects_inactive_actor(): void
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $fixture = $this->scope('extension');
        $this->requestScope($fixture, ['type' => 'extension']);
        $this->checkConstraints();
        DB::commit();
        $service = app(WorkshopAuthorizations::class);
        $diagnosis = DB::table('workshop_diagnoses')->where('work_order_id', $fixture['order'])->value('id');
        $data = ['diagnosis_id' => $diagnosis, 'description' => 'Inicial', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']], 'reason' => 'Inicial'];
        $requested = $service->requestScope($fixture['admin'], $fixture['order'], (string) Str::uuid(), $data);
        $this->assertSame('pending_authorization', DB::table('workshop_work_orders')->where('id', $fixture['order'])->value('status'));
        $decision = $service->decide($fixture['admin'], $requested['request_id'], (string) Str::uuid(), 'approved', 'Aprobar');
        $service->applyScope($fixture['admin'], $decision['decision_id'], (string) Str::uuid());
        $this->assertSame('authorized', DB::table('workshop_work_orders')->where('id', $fixture['order'])->value('status'));
        $this->assertSame(3, DB::table('workshop_work_orders')->where('id', $fixture['order'])->value('version'));
        $inactive = $this->actor('administrator', false);
        $this->expectException(AuthorizationException::class);
        $service->decide($inactive, $requested['request_id'], (string) Str::uuid(), 'approved', 'Inválido');
    }

    public function test_cancellation_and_reopening_require_exact_version_and_preserve_scope(): void
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $f = $this->scope();
        $r = $this->requestScope($f);
        $d = $this->decision($f, $r);
        $this->apply($f, $d);
        $this->checkConstraints();
        DB::commit();
        $service = app(WorkshopAuthorizations::class);
        $row = fn () => json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$f['order']])->data, true);
        $before = $row();
        $after = array_replace($before, ['status' => 'cancelled', 'version' => $before['version'] + 1]);
        $requested = $service->request($f['admin'], (string) Str::uuid(), ['work_order_id' => $f['order'], 'type' => 'cancellation', 'target_version' => $before['version'], 'content' => ['before' => $before, 'after' => $after], 'reason' => 'Cancelación documentada']);
        $decision = $service->decide($f['admin'], $requested['request_id'], (string) Str::uuid(), 'approved', 'Confirmar');
        $service->applyOrderDecision($f['admin'], $decision['decision_id'], (string) Str::uuid());
        $this->assertSame('cancelled', $row()['status']);
        $before = $row();
        $after = array_replace($before, ['status' => 'authorized', 'version' => $before['version'] + 1]);
        $requested = $service->request($f['admin'], (string) Str::uuid(), ['work_order_id' => $f['order'], 'type' => 'reopening', 'target_version' => $before['version'], 'content' => ['before' => $before, 'after' => $after], 'reason' => 'Reapertura documentada']);
        $decision = $service->decide($f['admin'], $requested['request_id'], (string) Str::uuid(), 'approved', 'Confirmar');
        $service->applyOrderDecision($f['admin'], $decision['decision_id'], (string) Str::uuid());
        $this->assertSame('authorized', $row()['status']);
        $this->assertSame(1, DB::table('workshop_scopes')->count());
        $this->assertSame(3, DB::table('workshop_authorization_applications')->count());
    }

    public function test_reopening_with_diagnosis_without_applied_scope_returns_pending_authorization(): void
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $f = $this->scope();
        $r = $this->requestScope($f);
        $this->decision($f, $r, 'rejected');
        $this->checkConstraints();
        DB::commit();
        $service = app(WorkshopAuthorizations::class);
        $row = fn () => json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$f['order']])->data, true);
        foreach (['cancellation' => 'cancelled', 'reopening' => 'pending_authorization'] as $type => $status) {
            $before = $row();
            $after = array_replace($before, ['status' => $status, 'version' => $before['version'] + 1]);
            $request = $service->request($f['admin'], (string) Str::uuid(), ['work_order_id' => $f['order'], 'type' => $type, 'target_version' => $before['version'], 'content' => ['before' => $before, 'after' => $after], 'reason' => 'Transición autorizada']);
            $decision = $service->decide($f['admin'], $request['request_id'], (string) Str::uuid(), 'approved', 'Confirmar');
            $service->applyOrderDecision($f['admin'], $decision['decision_id'], (string) Str::uuid());
            $this->assertSame($status, $row()['status']);
        }
    }

    public function test_cancelled_order_rejects_new_extension_and_previously_approved_extension_application(): void
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $f = $this->scope('extension');
        $r = $this->requestScope($f, ['type' => 'extension']);
        $extension = $this->decision($f, $r);
        $this->checkConstraints();
        DB::commit();
        $service = app(WorkshopAuthorizations::class);
        $before = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$f['order']])->data, true);
        $after = array_replace($before, ['status' => 'cancelled', 'version' => $before['version'] + 1]);
        $request = $service->request($f['admin'], (string) Str::uuid(), ['work_order_id' => $f['order'], 'type' => 'cancellation', 'target_version' => $before['version'], 'content' => ['before' => $before, 'after' => $after], 'reason' => 'Cancelación']);
        $decision = $service->decide($f['admin'], $request['request_id'], (string) Str::uuid(), 'approved', 'Confirmar');
        $service->applyOrderDecision($f['admin'], $decision['decision_id'], (string) Str::uuid());
        try {
            $service->applyScope($f['admin'], $extension, (string) Str::uuid());
            $this->fail('Aplicación en orden cancelada debe fallar.');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
        }
        $data = ['scope_kind' => 'extension', 'diagnosis_id' => DB::table('workshop_diagnoses')->where('work_order_id', $f['order'])->value('id'), 'description' => 'Nuevo alcance', 'tasks' => [['line_number' => 1, 'description' => 'Trabajo']], 'reason' => 'Solicitud'];
        $this->expectException(QueryException::class);
        $service->requestScope($f['admin'], $f['order'], (string) Str::uuid(), $data);
    }
}
