<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Application\PurchaseRequestAdministration;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class PurchaseRequestTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function payload(): array
    {
        $part = SparePart::create(['code' => 'REP-'.Str::uuid(), 'description' => 'Filtro', 'unit' => 'unidad']);
        $supplier = Supplier::create(['name' => 'Proveedor sintético']);

        return ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'reason' => 'Necesidad real comunicada', 'items' => [['spare_part_id' => $part->id, 'quantity' => '2.123456']]];
    }

    public function test_admin_request_exact_snapshot_decision_and_idempotency(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $data = $this->payload();
        $response = $this->actingAs($admin)->postJson('/api/purchasing/requests', $data)->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $id = $response->json('data.id');
        $response->assertJsonPath('data.items.0.quantity', '2.123456')->assertJsonPath('data.decision', null);
        $this->postJson('/api/purchasing/requests', $data)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/purchasing/requests', [...$data, 'reason' => 'Contenido distinto'])->assertConflict();
        $this->getJson('/api/purchasing/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/purchasing/requests/'.$id)->assertOk()->assertJsonPath('data.supplier.id', $data['supplier_id']);
        SparePart::findOrFail($data['items'][0]['spare_part_id'])->update(['description' => 'Nombre posterior']);
        $this->getJson('/api/purchasing/requests/'.$id)->assertOk()->assertJsonPath('data.items.0.description', 'Filtro');
        $decision = ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Alcance exacto revisado'];
        $this->postJson('/api/purchasing/requests/'.$id.'/decisions', $decision)->assertOk()->assertJsonPath('data.decision.decision', 'approved');
        $this->postJson('/api/purchasing/requests/'.$id.'/decisions', $decision)->assertOk();
        $this->postJson('/api/purchasing/requests/'.$id.'/decisions', [...$decision, 'request_key' => (string) Str::uuid(), 'decision' => 'rejected'])->assertConflict();
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('purchase_request_decisions', 1);
        $this->assertDatabaseCount('purchasing_operations', 2);
        $this->assertDatabaseCount('purchase_request_items', 1);
    }

    public function test_active_office_can_request_but_only_admin_decides_and_no_actor_is_supplied(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $data = $this->payload();
        $this->getJson('/api/purchasing/requests')->assertUnauthorized();
        foreach (['mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/purchasing/requests')->assertForbidden();
            $this->postJson('/api/purchasing/requests', $data)->assertForbidden();
        }
        $this->actingAs($this->actor('secretary'));
        $requestId = $this->postJson('/api/purchasing/requests', $data)->assertCreated()->json('data.id');
        $this->getJson('/api/purchasing/requests/'.$requestId)->assertOk();
        $this->postJson('/api/purchasing/requests/'.$requestId.'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Intento sin autorización'])->assertForbidden();
        $this->actingAs($this->actor('administrator', false))->postJson('/api/purchasing/requests', $data)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/purchasing/requests', [...$data, 'requested_by_id' => $admin->id])->assertUnprocessable()->assertJsonValidationErrors('requested_by_id');
        $admin->roles()->detach();
        $this->actingAs($admin->fresh())->postJson('/api/purchasing/requests', $data)->assertForbidden();
        $this->assertDatabaseCount('purchase_requests', 1);
    }

    public function test_authorized_unit_cannot_be_changed_or_approved_after_incompatible_catalog_edit(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $this->actingAs($admin);
        $data = $this->payload();
        $id = $this->postJson('/api/purchasing/requests', $data)->assertCreated()->json('data.id');
        $part = SparePart::findOrFail($data['items'][0]['spare_part_id']);
        $part->update(['unit' => 'caja']);
        $decision = ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Revisión exacta'];
        $this->postJson('/api/purchasing/requests/'.$id.'/decisions', $decision)->assertUnprocessable();
        $this->assertDatabaseCount('purchase_request_decisions', 0);
        $part->update(['unit' => 'unidad']);
        $this->postJson('/api/purchasing/requests/'.$id.'/decisions', $decision)->assertOk();
        $this->putJson('/api/catalogs/spare-parts/'.$part->id, ['code' => $part->code, 'description' => $part->description, 'unit' => 'caja'])->assertUnprocessable();
        $this->assertSame('unidad', $part->fresh()->unit);
    }

    public function test_validates_exact_positive_quantities_references_and_immutable_http_contract(): void
    {
        $this->prepareWorkshopSchema();
        $this->actingAs($this->actor());
        $data = $this->payload();
        foreach (['0', '0.000000', '-1', '2.0000001', '1000000000000', 'NaN', '1e2'] as $quantity) {
            $invalid = $data;
            $invalid['items'][0]['quantity'] = $quantity;
            $this->postJson('/api/purchasing/requests', $invalid)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        }
        $this->postJson('/api/purchasing/requests', [...$data, 'items' => []])->assertUnprocessable();
        $this->postJson('/api/purchasing/requests', [...$data, 'supplier_id' => (string) Str::uuid()])->assertUnprocessable();
        $id = $this->postJson('/api/purchasing/requests', $data)->assertCreated()->json('data.id');
        $this->getJson('/api/purchasing/requests/'.Str::uuid())->assertNotFound();
        $this->patchJson('/api/purchasing/requests/'.$id, ['reason' => 'Alteración'])->assertStatus(405);
        $this->deleteJson('/api/purchasing/requests/'.$id)->assertStatus(405);
        $this->getJson('/api/purchasing/requests?per_page=101')->assertUnprocessable();
    }

    private function rejectedSql(callable $work): void
    {
        DB::beginTransaction();
        try {
            $work();
            $this->fail('Se aceptó una escritura incompatible.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_postgresql_rejects_historical_edits_late_lines_and_missing_audit(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $data = $this->payload();
        $id = $this->actingAs($admin)->postJson('/api/purchasing/requests', $data)->assertCreated()->json('data.id');
        $this->rejectedSql(fn () => DB::table('purchase_requests')->where('id', $id)->update(['reason' => 'Alterado']));
        $this->rejectedSql(fn () => DB::table('purchase_request_items')->where('purchase_request_id', $id)->delete());
        $this->rejectedSql(fn () => DB::table('purchase_request_decisions')->insert(['id' => (string) Str::uuid(), 'purchase_request_id' => $id, 'decision' => 'approved', 'reason' => 'Sin auditoría', 'decided_by_id' => $admin->id]) && DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
        $part = SparePart::findOrFail($data['items'][0]['spare_part_id']);
        $this->rejectedSql(fn () => DB::table('purchase_request_items')->insert(['id' => (string) Str::uuid(), 'purchase_request_id' => $id, 'spare_part_id' => $part->id,
            'code_snapshot' => $part->code, 'description_snapshot' => $part->description, 'unit_snapshot' => $part->unit, 'quantity' => '1']));
        $this->rejectedSql(function () use ($admin, $data): void {
            DB::table('purchase_requests')->insert(['id' => (string) Str::uuid(), 'supplier_id' => $data['supplier_id'], 'reason' => 'Sin líneas', 'requested_by_id' => $admin->id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertDatabaseCount('purchase_request_items', 1);
        $this->assertDatabaseCount('purchase_request_decisions', 0);
    }

    public function test_workshop_need_links_to_exact_part_and_does_not_create_stock_or_delivery(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $this->actingAs($admin);
        $data = $this->payload();
        $asset = DB::transaction(function () {
            $asset = FleetAsset::create(['code' => 'NEED-'.Str::uuid(), 'type' => 'tractor_unit']);
            TractorUnit::create(['fleet_asset_id' => $asset->id]);

            return $asset;
        });
        $order = $this->postJson('/api/workshop/orders', ['request_key' => (string) Str::uuid(), 'fleet_asset_id' => $asset->id, 'kind' => 'corrective', 'description' => 'Filtro', 'priority' => 'normal', 'created_at' => now()->toIso8601String(), 'reason' => 'Reparar'])->assertCreated()->json('data.order_id');
        $need = $this->postJson('/api/workshop/orders/'.$order.'/material-needs', ['request_key' => (string) Str::uuid(), 'spare_part_id' => $data['items'][0]['spare_part_id'], 'quantity' => '2.123456', 'description' => 'Repuesto faltante', 'occurred_at' => now()->toIso8601String(), 'reason' => 'Solicitado'])->assertOk()->json('data.material_need_id');
        $data['items'][0]['workshop_material_need_id'] = $need;
        $this->postJson('/api/purchasing/requests', $data)->assertCreated()->assertJsonPath('data.items.0.workshop_material_need_id', $need);
        $other = SparePart::create(['code' => 'OTHER-'.Str::uuid(), 'description' => 'Otro', 'unit' => 'unidad']);
        $data['request_key'] = (string) Str::uuid();
        $data['items'][0]['spare_part_id'] = $other->id;
        $this->postJson('/api/purchasing/requests', $data)->assertUnprocessable();
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('workshop_part_deliveries', 0);
        $this->assertDatabaseCount('workshop_part_usages', 0);
    }

    public function test_concurrent_same_key_and_decisions_are_serialized_with_postgresql(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $data = $this->payload();
        $service = app(PurchaseRequestAdministration::class);
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET search_path TO '.DB::selectOne('SELECT current_schema() AS name')->name.',public');
        DB::beginTransaction();
        $created = $service->record($admin, $data);
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout='100ms'");
        try {
            $other->prepare('INSERT INTO purchase_requests(id,supplier_id,reason,requested_by_id) VALUES (?,?,?,?)')->execute([(string) Str::uuid(), $data['supplier_id'], 'Concurrente', $admin->id]);
            $this->fail('La segunda operación debía esperar al coordinador.');
        } catch (PDOException $exception) {
            $this->assertSame('55P03', $exception->getCode());
            $other->rollBack();
        }
        DB::commit();
        $this->assertSame($created->id, $service->record($admin, $data)->id);
        DB::beginTransaction();
        $decision = ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Revisión'];
        $service->record($admin, $decision, $created->id);
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout='100ms'");
        try {
            $other->prepare('INSERT INTO purchase_request_decisions(id,purchase_request_id,decision,reason,decided_by_id) VALUES (?,?,?,?,?)')->execute([(string) Str::uuid(), $created->id, 'rejected', 'Concurrente', $admin->id]);
            $this->fail('La decisión concurrente debía esperar.');
        } catch (PDOException $exception) {
            $this->assertSame('55P03', $exception->getCode());
            $other->rollBack();
        }
        DB::commit();
        $this->actingAs($admin)->postJson('/api/purchasing/requests/'.$created->id.'/decisions', [...$decision, 'request_key' => (string) Str::uuid(), 'decision' => 'rejected'])->assertConflict();
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('purchase_request_decisions', 1);
        $this->assertDatabaseCount('purchasing_operations', 2);
    }
}
