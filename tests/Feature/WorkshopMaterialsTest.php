<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Workshop\Application\WorkshopAttachments;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopCosts;
use App\Modules\Workshop\Application\WorkshopMaterialCorrections;
use App\Modules\Workshop\Application\WorkshopMaterials;
use App\Modules\Workshop\Application\WorkshopRectifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\InventoryFixtures;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopMaterialsTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    private ?string $filesRoot = null;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        if ($this->filesRoot !== null) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->filesRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->filesRoot);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $this->prepareWorkshopSchema();
        DB::beginTransaction();
        $admin = $this->actor();
        $mechanic = $this->actor('mechanic');
        $employee = Employee::create(['user_id' => $mechanic->id, 'name' => 'Mecánico prueba', 'is_active' => true]);
        $asset = FleetAsset::create(['code' => 'EXEC-'.Str::uuid(), 'type' => 'tractor_unit']);
        TractorUnit::create(['fleet_asset_id' => $asset->id]);
        $order = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $diagnosis = (string) Str::uuid();
        DB::table('workshop_work_orders')->insert(['id' => $order, 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Reparación', 'priority' => 'normal', 'status' => 'pending_diagnosis', 'created_at' => now(), 'created_by_id' => $admin->id]);
        $this->receipt($admin->id, $order, 'order_create');
        DB::table('workshop_mechanic_assignments')->insert(['id' => $assignment, 'work_order_id' => $order, 'employee_id' => $employee->id, 'starts_at' => '2026-10-12T08:00:00Z', 'assigned_by_id' => $admin->id, 'reason' => 'Asignado']);
        $this->receipt($admin->id, $order, 'mechanic_assign', ['mechanic_assignment_id' => $assignment]);
        DB::table('workshop_diagnoses')->insert(['id' => $diagnosis, 'work_order_id' => $order, 'problem' => 'Falla', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar', 'actor_id' => $admin->id, 'occurred_at' => now(), 'reason' => 'Prueba', 'root_record_id' => $diagnosis, 'revision' => 1]);
        $this->receipt($admin->id, $order, 'diagnosis_record', ['diagnosis_id' => $diagnosis]);
        DB::commit();
        $authorizations = app(WorkshopAuthorizations::class);
        $catalog = SparePart::create(['code' => 'PART-'.Str::uuid(), 'description' => 'Junta', 'unit' => 'unidad']);
        InventoryFixtures::reconcile($admin, $catalog, '2.000000');
        $scope = $authorizations->requestScope($admin, $order, (string) Str::uuid(), ['diagnosis_id' => $diagnosis, 'description' => 'Alcance', 'reason' => 'Prueba', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']], 'parts' => [['line_number' => 1, 'task_line_number' => 1, 'spare_part_id' => $catalog->id, 'quantity' => '2.000000']]]);
        $decision = $authorizations->decide($admin, $scope['request_id'], (string) Str::uuid(), 'approved', 'Aprobado');
        $authorizations->applyScope($admin, $decision['decision_id'], (string) Str::uuid());
        $task = DB::table('workshop_scope_tasks')->where('scope_id', $scope['scope_id'])->value('id');

        $part = DB::table('workshop_scope_parts')->where('scope_id', $scope['scope_id'])->value('id');

        return compact('admin', 'mechanic', 'order', 'assignment', 'task', 'part', 'catalog', 'scope', 'diagnosis');
    }

    private function receipt(int $actor, string $order, string $type, array $extra = []): void
    {
        DB::table('workshop_operations')->insert($extra + ['id' => (string) Str::uuid(), 'work_order_id' => $order, 'operation_type' => $type, 'actor_id' => $actor, 'reason' => 'Prueba', 'occurred_at' => now(), 'after_values' => '{}', 'confirmed_result' => '{}', 'request_key' => (string) Str::uuid(), 'idempotency_scope' => $type, 'request_fingerprint' => str_repeat('a', 64)]);
    }

    private function delivery(array $f, string $quantity = '2'): array
    {
        return app(WorkshopMaterials::class)->deliver($f['admin'], $f['order'], (string) Str::uuid(), ['scope_part_id' => $f['part'], 'delivered_to_assignment_id' => $f['assignment'], 'quantity' => $quantity, 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Entrega física']);
    }

    public function test_delivery_usage_replays_and_enforces_both_quotas(): void
    {
        $f = $this->fixture();
        $delivery = $this->delivery($f);
        $service = app(WorkshopMaterials::class);
        $data = ['delivery_id' => $delivery['delivery_id'], 'mechanic_assignment_id' => $f['assignment'], 'quantity' => '1.25', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Utilizado'];
        $key = (string) Str::uuid();
        $usage = $service->usePart($f['mechanic'], $f['order'], $key, $data);
        $this->assertSame($usage, $service->usePart($f['mechanic'], $f['order'], $key, $data));
        foreach ([fn () => $this->delivery($f, '0.1'), fn () => $service->usePart($f['mechanic'], $f['order'], (string) Str::uuid(), array_replace($data, ['quantity' => '0.750001']))] as $invalid) {
            try {
                $invalid();
                $this->fail('Cantidad incompatible aceptada');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame(1, DB::table('workshop_part_deliveries')->count());
        $this->assertSame(1, DB::table('workshop_part_usages')->count());
    }

    public function test_decimal_input_rejects_rounding_exponents_and_out_of_range(): void
    {
        $f = $this->fixture();
        foreach (['0.0000001', '1e0', '1000000000000', '-1', 'NaN'] as $quantity) {
            try {
                $this->delivery($f, $quantity);
                $this->fail('Decimal inválido aceptado');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame(0, DB::table('workshop_part_deliveries')->count());
    }

    public function test_cost_totals_are_exact_and_covered_components_are_not_counted_twice(): void
    {
        $f = $this->fixture();
        $service = app(WorkshopCosts::class);
        $data = ['scope_task_id' => $f['task'], 'cost_kind' => 'labor', 'basis' => 'actual', 'amount' => '99999999999999.123456', 'currency' => 'CRC', 'description' => 'Factura mano de obra', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Comunicación'];
        $key = (string) Str::uuid();
        $cost = $service->record($f['admin'], $f['order'], $key, $data);
        $this->assertSame($cost, $service->record($f['admin'], $f['order'], $key, $data));
        $this->assertSame('99999999999999.123456', DB::selectOne('SELECT after_values->>\'amount\' AS amount FROM workshop_operations WHERE cost_record_id=?', [$cost['cost_record_id']])->amount);
        $component = array_replace($data, ['scope_task_id' => null, 'scope_part_id' => $f['part'], 'cost_kind' => 'part', 'amount' => '12.345678', 'covered_by_cost_id' => $cost['cost_record_id'], 'description' => 'Componente cubierto']);
        $service->record($f['admin'], $f['order'], (string) Str::uuid(), $component);
        $this->assertSame('99999999999999.123456', $service->totals($f['admin'], $f['order'])[0]['amount']);
        try {
            $service->record($f['admin'], $f['order'], (string) Str::uuid(), $data);
            $this->fail('Costo duplicado aceptado');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(2, DB::table('workshop_cost_records')->count());
    }

    public function test_mechanic_cannot_record_delivery_or_cost(): void
    {
        $f = $this->fixture();
        $this->expectException(AuthorizationException::class);
        app(WorkshopMaterials::class)->deliver($f['mechanic'], $f['order'], (string) Str::uuid(), ['scope_part_id' => $f['part'], 'delivered_to_assignment_id' => $f['assignment'], 'quantity' => '1', 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'No autorizado']);
    }

    public function test_concurrent_delivery_waits_and_rechecks_authorized_balance(): void
    {
        $f = $this->fixture();
        $config = config('database.connections.pgsql_testing');
        $pdo = new \PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = explode(',', DB::selectOne('SHOW search_path')->search_path)[0];
        $this->assertMatchesRegularExpression('/^workshop_service_[a-f0-9]+$/', $schema);
        $pdo->exec('SET search_path TO '.$schema.',public');
        DB::beginTransaction();
        $row = ['id' => (string) Str::uuid(), 'scope_part_id' => $f['part'], 'delivered_to_assignment_id' => $f['assignment'], 'recorded_by_id' => $f['admin']->id, 'occurred_at' => '2026-10-12T09:00:00Z', 'reason' => 'Entrega concurrente'];
        DB::select('SELECT workshop_write_delivery(?::jsonb, ?::text)', [json_encode($row, JSON_THROW_ON_ERROR), '2']);
        $snapshot = DB::selectOne('SELECT to_jsonb(d)::text AS data FROM workshop_part_deliveries d WHERE id=?', [$row['id']])->data;
        $this->receipt($f['admin']->id, $f['order'], 'delivery_record', ['delivery_id' => $row['id'], 'after_values' => $snapshot]);
        $row['id'] = (string) Str::uuid();
        $stmt = $pdo->prepare('SELECT workshop_write_delivery(?::jsonb, ?::text)');
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $stmt->execute([json_encode($row, JSON_THROW_ON_ERROR), '1']);
                $this->fail('Debe esperar a entrega concurrente');
            } catch (\PDOException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
            }
            $pdo->rollBack();
            DB::commit();
            $pdo->beginTransaction();
            try {
                $stmt->execute([json_encode($row, JSON_THROW_ON_ERROR), '1']);
                $this->fail('Debe volver a validar saldo');
            } catch (\PDOException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->assertSame(1, DB::table('workshop_part_deliveries')->count());
    }

    public function test_approved_cost_revision_preserves_coverage_and_history(): void
    {
        $f = $this->fixture();
        $service = app(WorkshopCosts::class);
        $data = ['scope_task_id' => $f['task'], 'cost_kind' => 'labor', 'basis' => 'actual', 'amount' => '100.123456', 'currency' => 'CRC', 'description' => 'Costo inicial', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Comunicación'];
        $cost = $service->record($f['admin'], $f['order'], (string) Str::uuid(), $data);
        $component = $service->record($f['admin'], $f['order'], (string) Str::uuid(), array_replace($data, ['scope_task_id' => null, 'scope_part_id' => $f['part'], 'cost_kind' => 'part', 'amount' => '20', 'covered_by_cost_id' => $cost['cost_record_id'], 'description' => 'Pieza cubierta']));
        $version = DB::table('workshop_work_orders')->where('id', $f['order'])->value('version');
        $request = app(WorkshopRectifications::class)->request($f['admin'], (string) Str::uuid(), 'workshop_cost_records', $cost['cost_record_id'], $version, ['amount' => '200.123456', 'reason' => 'Rectificado'], $f['admin']->id, 'Corregir importe');
        $decision = app(WorkshopAuthorizations::class)->decide($f['admin'], $request['request_id'], (string) Str::uuid(), 'approved', 'Verificado');
        try {
            $service->revise($f['admin'], $decision['decision_id'], (string) Str::uuid());
            $this->fail('Padre revisado con referencias dependientes obsoletas');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $componentDecision = $this->approveCorrection($f, 'workshop_cost_records', $component['cost_record_id'], ['covered_by_cost_id' => $request['planned_record_id'], 'reason' => 'Seguir global rectificado']);
        $key = (string) Str::uuid();
        $decisions = [$decision['decision_id'], $componentDecision];
        $corrections = app(WorkshopMaterialCorrections::class);
        $revised = $corrections->applyMany($f['admin'], $decisions, $key);
        $this->assertSame($revised, $corrections->applyMany($f['admin'], $decisions, $key));
        $this->assertSame('200.123456', $service->totals($f['admin'], $f['order'])[0]['amount']);
        $this->assertSame(4, DB::table('workshop_cost_records')->count());
        $this->assertSame('100.123456', DB::table('workshop_cost_records')->where('id', $cost['cost_record_id'])->value('amount'));
    }

    private function approveCorrection(array $f, string $table, string $fact, array $changes): string
    {
        $version = DB::table('workshop_work_orders')->where('id', $f['order'])->value('version');
        $request = app(WorkshopRectifications::class)->request($f['admin'], (string) Str::uuid(), $table, $fact, $version, $changes, $f['admin']->id, 'Rectificación comprobada');
        $decision = app(WorkshopAuthorizations::class)->decide($f['admin'], $request['request_id'], (string) Str::uuid(), 'approved', 'Verificado');

        return $decision['decision_id'];
    }

    public function test_approved_usage_revision_and_delivery_floor_preserve_history(): void
    {
        $f = $this->fixture();
        $materials = app(WorkshopMaterials::class);
        $delivery = $this->delivery($f);
        $usage = $materials->usePart($f['mechanic'], $f['order'], (string) Str::uuid(), ['delivery_id' => $delivery['delivery_id'], 'mechanic_assignment_id' => $f['assignment'], 'quantity' => '1', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Uso inicial']);
        $decision = $this->approveCorrection($f, 'workshop_part_usages', $usage['usage_id'], ['quantity' => '1.5', 'reason' => 'Uso corregido']);
        $corrected = $materials->reviseUsage($f['admin'], $decision, (string) Str::uuid());
        $this->assertSame($f['admin']->id, DB::table('workshop_part_usages')->where('id', $corrected['usage_id'])->value('confirmed_by_id'));
        $balances = DB::selectOne('SELECT sum(custody_delta)::text AS custody, sum(consumed_delta)::text AS consumed FROM inventory_movements WHERE delivery_id=?', [$delivery['delivery_id']]);
        $this->assertSame('0.500000', $balances->custody);
        $this->assertSame('1.500000', $balances->consumed);
        $decision = $this->approveCorrection($f, 'workshop_part_deliveries', $delivery['delivery_id'], ['quantity' => '1.4', 'reason' => 'Corrección incompatible']);
        try {
            $materials->reviseDelivery($f['admin'], $decision, (string) Str::uuid());
            $this->fail('Entrega inferior al uso efectivo aceptada');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(1, DB::table('workshop_part_deliveries')->count());
        $this->assertSame(2, DB::table('workshop_part_usages')->count());
    }

    public function test_usage_correction_after_physical_return_restores_only_custody(): void
    {
        $f = $this->fixture();
        $delivery = $this->delivery($f);
        $materials = app(WorkshopMaterials::class);
        $ledger = app(InventoryLedger::class);
        $usage = $materials->usePart($f['mechanic'], $f['order'], (string) Str::uuid(), ['delivery_id' => $delivery['delivery_id'], 'mechanic_assignment_id' => $f['assignment'], 'quantity' => '1', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Uso comunicado']);
        $return = ['delivery_id' => $delivery['delivery_id'], 'quantity' => '0.5', 'occurred_at' => '2026-10-12T11:00:00Z', 'reason' => 'Recepción física de sobrante'];
        $ledger->returnParts($f['admin'], (string) Str::uuid(), $return);
        $balance = fn () => DB::selectOne('SELECT sum(warehouse_delta)::text AS warehouse, sum(custody_delta)::text AS custody, sum(consumed_delta)::text AS consumed FROM inventory_movements WHERE spare_part_id=?', [$f['catalog']->id]);
        $this->assertEquals((object) ['warehouse' => '0.500000', 'custody' => '0.500000', 'consumed' => '1.000000'], $balance());
        $decision = $this->approveCorrection($f, 'workshop_part_usages', $usage['usage_id'], ['quantity' => '0.5', 'reason' => 'Cantidad comunicada corregida']);
        $materials->reviseUsage($f['admin'], $decision, (string) Str::uuid());
        $this->assertEquals((object) ['warehouse' => '0.500000', 'custody' => '1.000000', 'consumed' => '0.500000'], $balance());
        $key = (string) Str::uuid();
        $return['quantity'] = '1';
        $confirmed = $ledger->returnParts($f['admin'], $key, $return);
        $this->assertSame($confirmed, $ledger->returnParts($f['admin'], $key, $return));
        $this->assertEquals((object) ['warehouse' => '1.500000', 'custody' => '0.000000', 'consumed' => '0.500000'], $balance());
        try {
            $ledger->returnParts($f['admin'], (string) Str::uuid(), array_replace($return, ['quantity' => '0.1']));
            $this->fail('Devolución superior a custodia aceptada');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('operation', $error->errors());
        }
        $this->assertSame(2, DB::table('inventory_returns')->count());
        $this->assertSame(2, DB::table('workshop_part_usages')->count());
    }

    public function test_second_assigned_mechanic_cannot_consume_another_mechanics_delivery(): void
    {
        $f = $this->fixture();
        $delivery = $this->delivery($f);
        $other = $this->actor('mechanic');
        $employee = Employee::create(['user_id' => $other->id, 'name' => 'Segundo mecánico', 'is_active' => true]);
        $assignment = (string) Str::uuid();
        DB::transaction(function () use ($f, $employee, $assignment): void {
            DB::table('workshop_mechanic_assignments')->insert(['id' => $assignment, 'work_order_id' => $f['order'], 'employee_id' => $employee->id, 'starts_at' => '2026-10-12T08:00:00Z', 'assigned_by_id' => $f['admin']->id, 'reason' => 'Segundo participante']);
            $this->receipt($f['admin']->id, $f['order'], 'mechanic_assign', ['mechanic_assignment_id' => $assignment]);
        });
        $data = ['delivery_id' => $delivery['delivery_id'], 'mechanic_assignment_id' => $assignment, 'quantity' => '0.5', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Entrega ajena'];
        $this->actingAs($other)->postJson('/api/workshop/orders/'.$f['order'].'/usages', $data + ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        try {
            DB::transaction(function () use ($data, $other): void {
                $quantity = $data['quantity'];
                unset($data['quantity']);
                DB::select('SELECT workshop_write_usage(?::jsonb, ?::text)', [json_encode($data + ['id' => (string) Str::uuid(), 'confirmed_by_id' => $other->id], JSON_THROW_ON_ERROR), $quantity]);
            });
            $this->fail('Consumo SQL de entrega ajena aceptado');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('workshop_part_usages')->count());
    }

    public function test_large_material_need_preserves_exact_audit_quantity(): void
    {
        $f = $this->fixture();
        $record = app(WorkshopMaterials::class)->requestMaterial($f['mechanic'], $f['order'], (string) Str::uuid(), ['spare_part_id' => $f['catalog']->id, 'quantity' => '999999999999.123456', 'description' => 'Necesidad comunicada', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Sin movimiento de inventario']);
        $this->assertSame('999999999999.123456', DB::selectOne("SELECT after_values->>'quantity' AS quantity FROM workshop_operations WHERE material_need_id=?", [$record['material_need_id']])->quantity);
    }

    public function test_scope_revision_cannot_lower_authorized_ceiling_below_deliveries(): void
    {
        $f = $this->fixture();
        $this->delivery($f);
        $service = app(WorkshopAuthorizations::class);
        $request = $service->requestScope($f['admin'], $f['order'], (string) Str::uuid(), ['scope_kind' => 'revision', 'previous_scope_id' => $f['scope']['scope_id'], 'diagnosis_id' => $f['diagnosis'], 'description' => 'Techo menor', 'reason' => 'Revisión', 'tasks' => [['line_number' => 1, 'description' => 'Reparar']], 'parts' => [['line_number' => 1, 'task_line_number' => 1, 'spare_part_id' => $f['catalog']->id, 'quantity' => '1']]]);
        $decision = $service->decide($f['admin'], $request['request_id'], (string) Str::uuid(), 'approved', 'Revisado');
        try {
            $service->applyScope($f['admin'], $decision['decision_id'], (string) Str::uuid());
            $this->fail('Autorización inferior a entrega efectiva aplicada');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertFalse(DB::table('workshop_authorization_applications')->where('decision_id', $decision['decision_id'])->exists());
    }

    public function test_secretary_cannot_communicate_use_and_mechanic_cannot_view_or_record_costs(): void
    {
        $f = $this->fixture();
        $delivery = $this->delivery($f);
        $secretary = $this->actor('secretary');
        $denied = [
            fn () => app(WorkshopMaterials::class)->usePart($secretary, $f['order'], (string) Str::uuid(), ['delivery_id' => $delivery['delivery_id'], 'mechanic_assignment_id' => $f['assignment'], 'quantity' => '1', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Inválido']),
            fn () => app(WorkshopCosts::class)->totals($f['mechanic'], $f['order']),
            fn () => app(WorkshopCosts::class)->record($f['mechanic'], $f['order'], (string) Str::uuid(), ['scope_task_id' => $f['task'], 'cost_kind' => 'labor', 'basis' => 'actual', 'amount' => '1', 'currency' => 'CRC', 'description' => 'Inválido', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Inválido']),
        ];
        foreach ($denied as $operation) {
            try {
                $operation();
                $this->fail('Permiso económico/uso incompatible aceptado');
            } catch (AuthorizationException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        try {
            DB::transaction(function () use ($f, $delivery, $secretary): void {
                $row = ['id' => (string) Str::uuid(), 'delivery_id' => $delivery['delivery_id'], 'mechanic_assignment_id' => $f['assignment'], 'confirmed_by_id' => $secretary->id, 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Escritura incompatible'];
                DB::select('SELECT workshop_write_usage(?::jsonb,?::text)', [json_encode($row, JSON_THROW_ON_ERROR), '1']);
            });
            $this->fail('Escritura SQL de secretaria aceptada');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('workshop_part_usages')->count());
    }

    public function test_documentary_cost_is_excluded_until_approved_classification(): void
    {
        $f = $this->fixture();
        $this->filesRoot = sys_get_temp_dir().'/workshop_cost_files_'.Str::uuid();
        mkdir($this->filesRoot, 0700);
        config(['workshop.storage_root' => $this->filesRoot]);
        $path = base_path('tests/Fixtures/fleet-documents/valid.png');
        $files = app(WorkshopAttachments::class);
        $reservation = $files->reserve($f['admin'], $f['order'], (string) Str::uuid(), ['category' => 'invoice', 'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => filesize($path), 'checksum' => hash_file('sha256', $path), 'reason' => 'Factura sintética']);
        $attachment = $files->confirm($f['admin'], $reservation['reservation_id'], (string) Str::uuid(), $path);
        $costs = app(WorkshopCosts::class);
        $record = $costs->record($f['admin'], $f['order'], (string) Str::uuid(), ['cost_kind' => null, 'basis' => 'actual', 'amount' => '123.456789', 'currency' => 'CRC', 'source_attachment_id' => $attachment['attachment_id'], 'description' => 'Desglose desconocido pendiente clasificación', 'occurred_at' => '2026-10-12T10:00:00Z', 'reason' => 'Documentado']);
        $this->assertSame([], $costs->totals($f['admin'], $f['order']));
        $decision = $this->approveCorrection($f, 'workshop_cost_records', $record['cost_record_id'], ['cost_kind' => 'labor', 'scope_task_id' => $f['task'], 'reason' => 'Clasificado según factura']);
        $classified = $costs->revise($f['admin'], $decision, (string) Str::uuid());
        $this->assertSame('cost_classify', DB::table('workshop_operations')->where('cost_record_id', $classified['cost_record_id'])->value('operation_type'));
        $this->assertSame('123.456789', $costs->totals($f['admin'], $f['order'])[0]['amount']);
        $this->assertNull(DB::table('workshop_cost_records')->where('id', $record['cost_record_id'])->value('cost_kind'));
    }
}
