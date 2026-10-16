<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\TransportAndDispatch\Models\TransportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\BillingFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class AdministrativeAuditTest extends PostgresTestCase
{
    use BillingFixtures, TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    public function test_only_active_administrators_can_consult_page_api_and_details(): void
    {
        $this->withoutVite();
        $this->get('/auditoria')->assertRedirect('/login');
        $this->getJson('/api/audit')->assertUnauthorized();
        foreach (['secretary', 'mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role))->get('/auditoria')->assertForbidden();
            $this->getJson('/api/audit')->assertForbidden();
            $this->getJson('/api/audit/changes/'.Str::uuid())->assertForbidden();
            $this->assertNotContains('/auditoria', array_column($this->getJson('/api/navigation')->json('data.modules'), 'href'));
        }
        $admin = $this->actor();
        $this->actingAs($admin)->get('/auditoria')->assertOk();
        $this->assertContains('/auditoria', array_column($this->getJson('/api/navigation')->json('data.modules'), 'href'));
        $admin->roles()->detach();
        $this->getJson('/api/audit')->assertForbidden();
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->getJson('/api/audit')->assertForbidden();
    }

    public function test_empty_results_validation_unknown_sources_and_read_only_methods(): void
    {
        $this->actingAs($this->actor());
        $response = $this->getJson('/api/audit')->assertOk()->assertJsonCount(0, 'data.rows')->assertJsonPath('data.meta.total', 0);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['from=invalid', 'from=2026-10-16&to=2026-10-15', 'module=unknown', 'operation=bad%27sql', 'actor_id=0', 'per_page=101', 'page=0'] as $query) {
            $this->getJson('/api/audit?'.$query)->assertUnprocessable();
        }
        foreach (['changes', 'unknown', 'users'] as $source) {
            $this->getJson('/api/audit/'.$source.'/'.Str::uuid())->assertNotFound();
        }
        $this->getJson('/api/audit/changes/invalid')->assertNotFound();
        foreach (['post', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/audit', [])->assertStatus(405);
        }
    }

    public function test_real_workshop_operations_are_paginated_once_and_project_only_safe_snapshots(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $asset = app(FleetAdministration::class)->save($admin, 'tractor_unit', ['code' => 'AUDIT-'.Str::uuid(), 'brand' => 'Sintética', 'model' => 'Prueba', 'year' => 2020]);
        $order = ReportFixtures::workshop($admin, $asset->id);
        $this->actingAs($admin);
        $count = DB::table('workshop_operations')->count();
        $response = $this->getJson('/api/audit?module=workshop&per_page=2')->assertOk()->assertJsonCount(2, 'data.rows')->assertJsonPath('data.meta.total', $count);
        $this->assertGreaterThan(2, $count);
        $all = [];
        $last = $response->json('data.meta.last_page');
        for ($page = 1; $page <= $last; $page++) {
            foreach ($this->getJson('/api/audit?module=workshop&per_page=2&page='.$page)->assertOk()->json('data.rows') as $row) {
                $all[] = $row['source'].':'.$row['id'];
                $this->assertSame($admin->id, $row['actor_id']);
                $this->assertSame($order, $row['record_id']);
                $detail = $this->getJson('/api/audit/'.$row['source'].'/'.$row['id'])->assertOk()->json('data');
                $this->assertSame($row, $detail['event']);
                $json = json_encode($detail);
                foreach (['request_key', 'fingerprint', 'confirmed_result', 'storage_key', 'password', 'token', 'description', 'problem', 'reason', 'email'] as $private) {
                    $this->assertStringNotContainsString('"'.$private.'"', $json);
                }
            }
        }
        $this->assertCount($count, array_unique($all));
        $operation = $response->json('data.rows.0.operation');
        $expected = DB::table('workshop_operations')->where('operation_type', $operation)->count();
        $this->getJson('/api/audit?module=workshop&actor_id='.$admin->id.'&operation='.$operation)->assertOk()->assertJsonPath('data.meta.total', $expected);
        $other = $this->actor();
        $this->getJson('/api/audit?actor_id='.$other->id)->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/audit?to=2000-01-01')->assertOk()->assertJsonCount(0, 'data.rows');
        $today = now('America/Costa_Rica')->toDateString();
        $this->getJson('/api/audit?module=workshop&from='.$today.'&to='.$today)->assertOk()->assertJsonPath('data.meta.total', $count);
        $this->assertSame($count, DB::table('workshop_operations')->count());
        $this->assertSame(3, DB::table('workshop_activities')->count());
    }

    public function test_real_transport_billing_expenses_inventory_and_reviews_keep_canonical_identity(): void
    {
        $this->prepareWorkshopSchema();
        DB::table('billing_guard')->insert(['id' => 1, 'version' => 0]);
        $trip = DB::transaction(fn () => $this->closedTrip());
        $admin = $trip->registeredBy;
        $this->actingAs($admin);
        $this->approvedBillingAmount($trip, '100.03');
        $invoice = $this->billingInvoice($trip);
        $payment = $this->billingPayment('0.01');
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $payment)->assertCreated();
        $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $payment)->assertCreated();
        $expense = ['request_key' => (string) Str::uuid(), 'concept' => 'Gasto prueba', 'category' => 'Operación', 'amount' => '1.01', 'currency' => 'USD', 'occurred_at' => now()->toIso8601String()];
        $this->postJson('/api/expenses', $expense)->assertCreated();
        $this->postJson('/api/expenses', $expense)->assertCreated();
        $part = SparePart::create(['code' => 'AUDIT-PART', 'description' => 'Prueba', 'unit' => 'unidad']);
        InventoryFixtures::reconcile($admin, $part, '1.123456');
        $type = DocumentType::create(['name' => 'Vencimiento', 'tracks_expiration' => true, 'required_for_tractor_units' => false, 'required_for_tankers' => false]);
        $asset = $trip->assetAssignments()->where('asset_type', 'tractor_unit')->firstOrFail()->fleet_asset_id;
        $document = Document::create(['fleet_asset_id' => $asset, 'document_type_id' => $type->id, 'storage_key' => 'fleet/private-proof.pdf', 'expires_on' => now('America/Costa_Rica')->toDateString(), 'uploaded_by_id' => $admin->id])->refresh();
        $this->postJson('/api/document-alerts/'.$document->id.'/review')->assertOk();
        $this->postJson('/api/document-alerts/'.$document->id.'/review')->assertOk();
        foreach (['billing' => ['billing_operations'], 'expenses' => ['expense_operations'], 'inventory' => ['inventory_operations'], 'fleet' => ['documents'], 'alerts' => ['document_alert_reviews'], 'transport' => ['transport_requests', 'trips', 'transport_changes', 'transport_assignment_operations', 'transport_authorization_requests', 'transport_authorization_decisions']] as $module => $tables) {
            $expected = array_sum(array_map(fn ($table) => DB::table($table)->count(), $tables));
            $rows = $this->getJson('/api/audit?module='.$module.'&per_page=100')->assertOk()->assertJsonPath('data.meta.total', $expected)->json('data.rows');
            $this->assertCount($expected, array_unique(array_map(fn ($r) => $r['source'].':'.$r['id'], $rows)));
            foreach ($rows as $row) {
                $this->getJson('/api/audit/'.$row['source'].'/'.$row['id'])->assertOk()->assertJsonPath('data.event.id', $row['id']);
            }
        }
        $this->getJson('/api/audit?module=billing&operation=payment')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/audit?module=alerts')->assertOk()->assertJsonPath('data.meta.total', 1);
        $json = $this->getJson('/api/audit?per_page=100')->assertOk()->getContent();
        foreach (['storage_key', 'private-proof', 'request_key', 'fingerprint', 'password', 'email', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_purchase_receipts_are_not_duplicated_as_inventory_operations(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $this->actingAs($admin);
        $part = SparePart::create(['code' => 'AUDIT-PURCHASE', 'description' => 'Repuesto', 'unit' => 'unidad']);
        InventoryFixtures::reconcile($admin, $part, '0');
        $supplier = Supplier::create(['name' => 'Proveedor prueba']);
        $request = $this->postJson('/api/purchasing/requests', ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'reason' => 'Necesidad', 'items' => [['spare_part_id' => $part->id, 'quantity' => '2']]])->assertCreated()->json('data');
        $this->postJson('/api/purchasing/requests/'.$request['id'].'/decisions', ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Revisión'])->assertOk();
        $order = $this->postJson('/api/purchasing/orders', ['request_key' => (string) Str::uuid(), 'purchase_request_id' => $request['id'], 'reason' => 'Orden', 'items' => [['purchase_request_item_id' => $request['items'][0]['id'], 'quantity' => '2']]])->assertCreated()->json('data');
        $receipt = ['request_key' => (string) Str::uuid(), 'received_at' => now()->toIso8601String(), 'reason' => 'Recepción física', 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => '1']]];
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', $receipt)->assertOk();
        $this->postJson('/api/purchasing/orders/'.$order['id'].'/receipts', $receipt)->assertOk();
        $rows = $this->getJson('/api/audit?module=purchasing')->assertOk()->assertJsonPath('data.meta.total', 4)->json('data.rows');
        $receiptRow = collect($rows)->firstWhere('operation', 'receipt_create');
        $this->assertSame(DB::table('purchase_receipts')->value('id'), $receiptRow['record_id']);
        $this->getJson('/api/audit/fulfillment/'.$receiptRow['id'])->assertOk()->assertJsonPath('data.before', null);
        $this->getJson('/api/audit?module=inventory')->assertOk()->assertJsonPath('data.meta.total', 3);
        $this->assertSame(2, DB::table('inventory_movements')->count());
    }

    public function test_period_uses_complete_costa_rica_days(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin);
        $base = $this->request($admin);
        $attributes = $base->only(['customer_id', 'liquid_product_id', 'route_id', 'requested_liters', 'requested_at', 'registered_by_id']);
        foreach (['2026-10-15 05:59:59+00', '2026-10-15 06:00:00+00', '2026-10-16 05:59:59+00', '2026-10-16 06:00:00+00'] as $at) {
            // Synthetic historical creation timestamp, never alteration of a saved event.
            $request = new TransportRequest($attributes);
            $request->forceFill(['registered_at' => $at])->save();
        }
        $this->getJson('/api/audit?module=transport&operation=request_registered&from=2026-10-15&to=2026-10-15')->assertOk()->assertJsonPath('data.meta.total', 2);
    }
}
