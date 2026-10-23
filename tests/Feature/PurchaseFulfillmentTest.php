<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Application\PurchaseFulfillment;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class PurchaseFulfillmentTest extends PostgresTestCase
{
    use TransportFixtures,WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function prepared(): array
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $this->actingAs($admin);
        $part = SparePart::create(['code' => 'P-'.Str::uuid(), 'description' => 'Filtro', 'unit' => 'unidad']);
        $supplier = Supplier::create(['name' => 'Proveedor']);
        $request = $this->postJson('/api/purchasing/requests', ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'reason' => 'Necesidad', 'items' => [['spare_part_id' => $part->id, 'quantity' => '5.123456']]])->assertCreated()->json('data');
        $body = ['request_key' => (string) Str::uuid(), 'purchase_request_id' => $request['id'], 'reason' => 'Compra autorizada', 'items' => [['purchase_request_item_id' => $request['items'][0]['id'], 'quantity' => '5.123456']]];

        return [$admin, $part, $request, $body];
    }

    private function approve(string $id): void
    {
        $this->postJson('/api/purchasing/requests/'.$id.'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Revisado'])->assertOk();
    }

    private function initialize($admin, $part): void
    {
        $service = app(InventoryLedger::class);
        $time = now()->toIso8601String();
        $c = $service->correction($admin, (string) Str::uuid(), ['spare_part_id' => $part->id, 'kind' => 'initial', 'quantity' => '0', 'unit_snapshot' => 'unidad', 'occurred_at' => $time, 'reason' => 'Conteo físico cero']);
        $d = $service->decide($admin, $c['correction_id'], (string) Str::uuid(), ['decision' => 'approved', 'reason' => 'Conteo comprobado', 'occurred_at' => $time]);
        $service->apply($admin, $d['decision_id'], (string) Str::uuid(), ['occurred_at' => $time]);
    }

    public function test_approved_order_partial_receipts_stock_and_duplicate_keys(): void
    {
        [$admin,$part,$request,$body] = $this->prepared();
        $this->postJson('/api/purchasing/orders', $body)->assertUnprocessable();
        $this->approve($request['id']);
        $order = $this->postJson('/api/purchasing/orders', $body)->assertCreated()->json('data');
        $this->postJson('/api/purchasing/orders', $body)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->postJson('/api/purchasing/orders', [...$body, 'reason' => 'Diferente'])->assertConflict();
        $receipt = ['request_key' => (string) Str::uuid(), 'received_at' => '2026-10-14T08:30:12.123456-06:00', 'reason' => 'Recepción física parcial', 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '2.000001']]];
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', $receipt)->assertUnprocessable();
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->initialize($admin, $part);
        $response = $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', $receipt)->assertOk()->assertJsonPath('data.items.0.received_quantity', '2.000001')->assertJsonPath('data.items.0.pending_quantity', '3.123455');
        $receiptId = $response->json('data.receipts.0.id');
        $times = DB::selectOne("SELECT to_char(r.received_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS received, r.received_at=m.occurred_at AS same_instant FROM purchase_receipts r JOIN purchase_receipt_items i ON i.purchase_receipt_id=r.id JOIN inventory_movements m ON m.purchase_receipt_item_id=i.id WHERE r.id=?", [$receiptId]);
        $this->assertSame('2026-10-14 14:30:12.123456', $times->received);
        $this->assertTrue($times->same_instant);
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', $receipt)->assertOk()->assertJsonPath('data.receipts.0.id', $receiptId);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertSame('2.000001', DB::table('inventory_movements')->selectRaw('sum(warehouse_delta)::text AS quantity')->value('quantity'));
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', [...$receipt, 'request_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '3.123456']]])->assertUnprocessable();
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', [...$receipt, 'request_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '3.123455']]])->assertOk()->assertJsonPath('data.items.0.pending_quantity', '0.000000');
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertSame('5.123456', DB::table('inventory_movements')->selectRaw('sum(warehouse_delta)::text AS quantity')->value('quantity'));
    }

    public function test_order_quota_foreign_lines_office_permissions_and_revocation(): void
    {
        [$admin,$part,$request,$body] = $this->prepared();
        $this->approve($request['id']);
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $order = $this->postJson('/api/purchasing/orders', $body)->assertCreated()->json('data');
        $this->getJson('/api/purchasing/orders')->assertOk();
        $this->getJson('/api/purchasing/lookups')->assertOk();
        $this->postJson('/api/purchasing/orders', [...$body, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/purchasing/orders', [...$body, 'created_by_id' => $admin->id])->assertUnprocessable();
        foreach (['mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/purchasing/orders')->assertForbidden();
            $this->postJson('/api/purchasing/orders', $body)->assertForbidden();
        }
        $secretary->roles()->detach();
        $this->actingAs($secretary->fresh())->getJson('/api/purchasing/orders')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/purchasing/orders/'.Str::uuid())->assertNotFound();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 1);
    }

    public function test_sql_history_and_concurrent_receipts_cannot_bypass_integrity(): void
    {
        [$admin,$part,$request,$body] = $this->prepared();
        $this->approve($request['id']);
        $order = $this->postJson('/api/purchasing/orders', $body)->assertCreated()->json('data');
        $this->initialize($admin, $part);
        DB::beginTransaction();
        try {
            DB::table('inventory_movements')->insert(['id' => (string) Str::uuid(), 'spare_part_id' => $part->id, 'unit_snapshot' => 'unidad', 'kind' => 'receipt', 'warehouse_delta' => '1', 'custody_delta' => '0', 'consumed_delta' => '0', 'application_id' => DB::table('inventory_movements')->where('kind', 'initial')->value('application_id'), 'actor_id' => $admin->id, 'occurred_at' => now()->toIso8601String()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Entrada de compra sin recepción física válida.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        DB::beginTransaction();
        try {
            DB::table('purchase_orders')->where('id', $order['id'])->update(['reason' => 'Alterado']);
            $this->fail('Historial editable');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        DB::beginTransaction();
        try {
            $rid = (string) Str::uuid();
            DB::table('purchase_receipts')->insert(['id' => $rid, 'purchase_order_id' => $order['id'], 'received_by_id' => $admin->id, 'received_at' => now(), 'reason' => 'Sin entrada']);
            DB::table('purchase_receipt_items')->insert(['id' => (string) Str::uuid(), 'purchase_receipt_id' => $rid, 'purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '1']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Recepción sin inventario');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        $config = config('database.connections.pgsql_testing');
        $other = new \PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET search_path TO '.DB::selectOne('SELECT current_schema() AS name')->name.',public');
        app(PurchaseFulfillment::class)->record($admin, ['request_key' => (string) Str::uuid(), 'received_at' => now()->toIso8601String(), 'reason' => 'Primera', 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '5.123456']]], $order['id']);
        // Hold the coordinator independently of the completed service command.
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(873210)');
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout='100ms'");
        try {
            $other->prepare('INSERT INTO purchase_receipts(id,purchase_order_id,received_by_id,received_at,reason) VALUES(?,?,?,?,?)')->execute([(string) Str::uuid(), $order['id'], $admin->id, now()->toIso8601String(), 'Concurrente']);
            $this->fail('No serializada');
        } catch (\PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
            $other->rollBack();
        }DB::commit();
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', ['request_key' => (string) Str::uuid(), 'received_at' => now()->toIso8601String(), 'reason' => 'Fuera de cuota', 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '1']]])->assertUnprocessable();
        $this->assertDatabaseCount('purchase_receipts', 1);
    }
}
