<?php

namespace Tests\Feature;

use App\Modules\Expenses\Application\ExpenseAdministration;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\Inventory\Models\SparePart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\BillingFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class AdministrativeReportsTest extends PostgresTestCase
{
    use BillingFixtures, TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function prepare(): void
    {
        $this->prepareWorkshopSchema();
        DB::table('billing_guard')->insert(['id' => 1, 'version' => 0]);
        $this->actingAs($this->actor());
        $this->withoutVite();
    }

    public function test_only_active_administrators_can_read_pages_apis_and_navigation(): void
    {
        $this->withoutVite();
        $this->get('/reportes')->assertRedirect('/login');
        $this->getJson('/api/reports/transport')->assertUnauthorized();
        foreach (['secretary', 'mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role))->get('/reportes')->assertForbidden();
            foreach (['transport', 'billing', 'expenses', 'workshop', 'stock', 'movements'] as $kind) {
                $this->getJson('/api/reports/'.$kind)->assertForbidden();
            }
            $this->assertNotContains('/reportes', array_column($this->getJson('/api/navigation')->json('data.modules'), 'href'));
        }
        $admin = $this->actor();
        $this->actingAs($admin)->get('/reportes')->assertOk();
        $this->assertContains('/reportes', array_column($this->getJson('/api/navigation')->json('data.modules'), 'href'));
        $admin->roles()->detach();
        $this->getJson('/api/reports/transport')->assertForbidden();
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->getJson('/api/reports/transport')->assertForbidden();
    }

    public function test_empty_reports_filters_validation_and_read_only_methods(): void
    {
        $this->prepare();
        foreach (['transport', 'billing', 'expenses', 'workshop', 'stock', 'movements'] as $kind) {
            $response = $this->getJson('/api/reports/'.$kind)->assertOk()->assertJsonCount(0, 'data.rows')->assertJsonPath('data.meta.total', 0);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->postJson('/api/reports/'.$kind, [])->assertStatus(405);
        }
        foreach (['from=invalid', 'from=2026-10-15&to=2026-10-14', 'status=made_up', 'per_page=101', 'customer_id=invalid'] as $filter) {
            $this->getJson('/api/reports/transport?'.$filter)->assertUnprocessable();
        }
        $this->getJson('/api/reports/stock?from=2026-10-14')->assertUnprocessable();
        $this->getJson('/api/reports/expenses?customer_id='.(string) Str::uuid())->assertUnprocessable();
        $this->getJson('/api/reports/transport?to=2026-10-14')->assertOk();
        $this->getJson('/api/reports/unknown')->assertNotFound();
    }

    public function test_transport_customer_state_period_pagination_and_historical_assets_do_not_duplicate(): void
    {
        $this->prepare();
        $admin = auth()->user();
        $trip = DB::transaction(fn () => $this->trip($this->request($admin)));
        $this->ensureAssignments($trip);
        $asset = $trip->assetAssignments()->first()->fleet_asset_id;
        DB::transaction(fn () => $this->trip($trip->transportRequest));
        $url = '/api/reports/transport?customer_id='.$trip->transportRequest->customer_id;
        $this->getJson($url.'&per_page=1')->assertOk()->assertJsonPath('data.meta.total', 2)->assertJsonCount(1, 'data.rows')->assertJsonPath('data.summary.0.count', 2);
        $this->getJson($url.'&fleet_asset_id='.$asset)->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.rows.0.id', $trip->id);
        $this->getJson($url.'&status=completed')->assertOk()->assertJsonPath('data.meta.total', 0);
        $date = $trip->registered_at->setTimezone('America/Costa_Rica')->format('Y-m-d');
        $this->getJson($url.'&from='.$date.'&to='.$date)->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson($url.'&to=2000-01-01')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->assertDatabaseCount('trips', 2);
        $this->assertDatabaseCount('trip_asset_assignments', 2);
    }

    public function test_billing_totals_do_not_multiply_by_payments_and_currencies_are_separate(): void
    {
        $this->prepare();
        $crc = DB::transaction(fn () => $this->closedTrip());
        $this->actingAs($crc->registeredBy);
        $this->approvedBillingAmount($crc, '100.03');
        $invoice = $this->billingInvoice($crc);
        foreach (['0.01', '0.02'] as $amount) {
            $this->postJson('/api/billing/invoices/'.$invoice.'/payments', $this->billingPayment($amount))->assertCreated();
        }
        $usd = DB::transaction(fn () => $this->closedTrip());
        $this->actingAs($usd->registeredBy);
        $this->approvedBillingAmount($usd, '0.02', 'USD');
        $other = $this->billingInvoice($usd);
        $this->postJson('/api/billing/invoices/'.$other.'/payments', $this->billingPayment('0.01', 'USD'))->assertCreated();
        $response = $this->getJson('/api/reports/billing')->assertOk()->assertJsonPath('data.meta.total', 2);
        $summary = collect($response->json('data.summary.invoices'))->keyBy('currency');
        $this->assertSame('100.03', $summary['CRC']['invoiced']);
        $this->assertSame('0.03', $summary['CRC']['paid_to_date']);
        $this->assertSame('100.00', $summary['CRC']['balance']);
        $this->assertSame('0.02', $summary['USD']['invoiced']);
        $this->assertSame('0.01', $summary['USD']['balance']);
        $this->getJson('/api/reports/billing?currency=USD&status=partially_paid')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/reports/billing?customer_id='.$crc->transportRequest->customer_id)->assertOk()->assertJsonCount(1, 'data.summary.invoices')->assertJsonPath('data.summary.invoices.0.currency', 'CRC');
        $period = $this->getJson('/api/reports/billing?from=2026-10-14&to=2026-10-14')->assertOk()->json('data.summary.receipts');
        $this->assertSame('0.03', collect($period)->keyBy('currency')['CRC']['received']);
        $this->assertDatabaseCount('billing_payments', 3);
    }

    public function test_expense_revisions_status_and_costa_rica_complete_day_boundaries(): void
    {
        $this->prepare();
        $service = app(ExpenseAdministration::class);
        $admin = auth()->user();
        $data = ['trip_id' => null, 'concept' => 'Gasto corregido', 'category' => 'Administrativo', 'amount' => '100.03', 'currency' => 'CRC', 'occurred_at' => '2026-10-14T06:00:00Z', 'description' => 'Datos sintéticos'];
        $first = $service->create($admin, (string) Str::uuid(), $data);
        $service->decide($admin, $first['expense_id'], (string) Str::uuid(), ['expected_revision_id' => $first['revision_id'], 'decision' => 'approved', 'reason' => 'Revisión']);
        unset($data['trip_id']);
        $service->correct($admin, $first['expense_id'], (string) Str::uuid(), [...$data, 'amount' => '20.04', 'supersedes_id' => $first['revision_id'], 'reason' => 'Corrección']);
        foreach (['2026-10-15T05:59:59.999999Z', '2026-10-15T06:00:00Z'] as $at) {
            $service->create($admin, (string) Str::uuid(), [...$data, 'amount' => '1.01', 'currency' => 'USD', 'occurred_at' => $at]);
        }
        $this->getJson('/api/reports/expenses?from=2026-10-14&to=2026-10-14')->assertOk()->assertJsonPath('data.meta.total', 2)->assertJsonPath('data.summary.0.amount', '20.04')->assertJsonPath('data.summary.0.status', 'pending');
        $this->getJson('/api/reports/expenses?status=approved')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/reports/expenses?currency=USD')->assertOk()->assertJsonPath('data.meta.total', 2)->assertJsonPath('data.summary.0.amount', '2.02');
        $this->assertDatabaseCount('expense_revisions', 4);
        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_inventory_matches_existing_ledger_without_inventing_stock(): void
    {
        $this->prepare();
        $part = SparePart::create(['code' => 'REPORT-COUNTED', 'description' => 'Conteo sintético', 'unit' => 'unidad']);
        SparePart::create(['code' => 'REPORT-UNKNOWN', 'description' => 'Sin conciliación', 'unit' => 'litro']);
        InventoryFixtures::reconcile(auth()->user(), $part, '2.123456');
        $this->getJson('/api/reports/stock')->assertOk()->assertJsonPath('data.summary.parts', 2)->assertJsonPath('data.summary.parts_with_movements', 1);
        $this->getJson('/api/reports/stock?spare_part_id='.$part->id)->assertOk()->assertJsonPath('data.rows.0.available', '2.123456')->assertJsonPath('data.rows.0.initialized', true);
        $this->getJson('/api/reports/movements?status=initial')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.rows.0.warehouse_delta', '2.123456');
        $this->getJson('/api/reports/movements?status=delivery')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_workshop_orders_and_current_activities_do_not_count_corrections_twice(): void
    {
        $this->prepare();
        $asset = app(FleetAdministration::class)->save(auth()->user(), 'tractor_unit', ['code' => 'REPORT-HEAD']);
        $order = ReportFixtures::workshop(auth()->user(), $asset->id);
        $this->getJson('/api/reports/workshop?fleet_asset_id='.$asset->id.'&status=in_repair')->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.rows.0.id', $order)
            ->assertJsonPath('data.rows.0.activities', 2)->assertJsonPath('data.summary.0.count', 1)
            ->assertJsonPath('data.summary.0.activities', 2);
        $this->getJson('/api/reports/workshop?status=closed')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->assertDatabaseCount('workshop_activities', 3);
        $this->assertDatabaseCount('workshop_work_orders', 1);
    }
}
