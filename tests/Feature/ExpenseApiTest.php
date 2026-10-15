<?php

namespace Tests\Feature;

use App\Modules\BillingAndPayments\Application\Money;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Application\TransportAdministration;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class ExpenseApiTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function prepared(): array
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $trip = $this->trip($this->request($admin));
        $this->ensureAssignments($trip);
        $driver = $trip->driverAssignments()->current()->firstOrFail()->employee->user;
        $this->actingAs($admin);
        Storage::fake('fleet_documents');

        return [$admin, $trip, $driver];
    }

    private function expense(array $overrides = []): array
    {
        return $overrides + ['request_key' => (string) Str::uuid(), 'trip_id' => null, 'concept' => 'Compra operativa sintética',
            'category' => 'Operación', 'amount' => '100.03', 'currency' => 'CRC', 'occurred_at' => '2026-10-14T09:00:12.123456-06:00',
            'description' => 'Registro administrativo sin efecto en saldos de clientes'];
    }

    private function createExpense(array $overrides = []): array
    {
        return $this->postJson('/api/expenses', $this->expense($overrides))->assertCreated()->json('data');
    }

    public function test_general_and_trip_expenses_require_active_office_or_current_driver_without_auto_approval(): void
    {
        [$admin, $trip, $driver] = $this->prepared();
        $response = $this->getJson('/api/expenses')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $general = $this->createExpense();
        $this->getJson('/api/expenses/'.$general['expense_id'])->assertOk()->assertJsonPath('data.status', 'pending');
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $office = $this->createExpense(['trip_id' => $trip->id, 'currency' => 'USD']);
        $decision = ['request_key' => (string) Str::uuid(), 'expected_revision_id' => $office['revision_id'], 'decision' => 'approved', 'reason' => 'Revisión'];
        $this->postJson('/api/expenses/'.$office['expense_id'].'/decisions', $decision)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/expenses/'.$office['expense_id'].'/decisions', $decision)->assertOk();
        $this->getJson('/api/expenses/'.$office['expense_id'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.amount', '100.03');
        $this->actingAs($driver);
        $own = $this->createExpense(['trip_id' => $trip->id]);
        $this->getJson('/api/expenses/'.$own['expense_id'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/expenses', $this->expense())->assertStatus(403);
        $this->getJson('/api/expenses/'.$general['expense_id'])->assertNotFound();
        $this->actingAs($this->actor('mechanic'))->getJson('/api/expenses')->assertForbidden();
        $this->postJson('/api/expenses', $this->expense())->assertForbidden();
        $this->actingAs($this->actor('secretary', false))->postJson('/api/expenses', $this->expense())->assertForbidden();
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_exact_money_validation_and_retry_do_not_duplicate_expense_or_change_original_intent(): void
    {
        $this->prepared();
        $body = $this->expense(['amount' => '0.03', 'currency' => 'USD']);
        $first = $this->postJson('/api/expenses', $body)->assertCreated()->json('data');
        $this->postJson('/api/expenses', $body)->assertCreated()->assertJsonPath('data.expense_id', $first['expense_id']);
        $this->postJson('/api/expenses', [...$body, 'amount' => '0.04'])->assertConflict();
        foreach (['0', '-1.00', '0.001', '1e2', 'NaN', '1,00'] as $invalid) {
            $this->postJson('/api/expenses', $this->expense(['amount' => $invalid]))->assertUnprocessable();
        }
        $this->postJson('/api/expenses', $this->expense(['currency' => 'EUR']))->assertUnprocessable();
        $this->postJson('/api/expenses', $this->expense(['occurred_at' => '2026-10-14']))->assertUnprocessable();
        $this->getJson('/api/expenses/'.$first['expense_id'])->assertOk()->assertJsonPath('data.amount', '0.03');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame('0.03', Money::format(Money::minor('0.01') + Money::minor('0.02')));
    }

    public function test_decisions_and_corrections_preserve_original_history_and_require_new_revision_approval(): void
    {
        [$admin] = $this->prepared();
        $record = $this->createExpense();
        $url = '/api/expenses/'.$record['expense_id'];
        $decision = ['request_key' => (string) Str::uuid(), 'expected_revision_id' => $record['revision_id'], 'decision' => 'approved', 'reason' => 'Monto autorizado'];
        $this->postJson($url.'/decisions', $decision)->assertOk();
        $this->postJson($url.'/decisions', $decision)->assertOk();
        $correction = $this->expense(['amount' => '110.04', 'supersedes_id' => $record['revision_id'], 'reason' => 'Corrección documentada']);
        unset($correction['trip_id']);
        $revision = $this->actingAs($this->actor('secretary'))->postJson($url.'/corrections', $correction)->assertCreated()->json('data.revision_id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount', '110.04');
        $this->postJson($url.'/corrections', [...$correction, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->actingAs($admin)->postJson($url.'/decisions', [...$decision, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($url.'/decisions', [...$decision, 'request_key' => (string) Str::uuid(), 'expected_revision_id' => $revision, 'decision' => 'rejected'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseCount('expense_revisions', 2);
        $this->assertDatabaseCount('expense_decisions', 2);
        $this->assertDatabaseHas('expense_revisions', ['id' => $record['revision_id'], 'amount' => '100.03']);
        $this->rejected(fn () => DB::table('expense_revisions')->where('id', $record['revision_id'])->update(['amount' => '1.00']));
        $this->rejected(fn () => DB::table('expense_decisions')->delete());
    }

    public function test_driver_expense_ownership_and_release_are_rechecked_but_own_history_remains_readable(): void
    {
        [$admin, $trip, $driver] = $this->prepared();
        $other = $this->trip($this->request($admin));
        $this->ensureAssignments($other);
        $this->actingAs($driver);
        $record = $this->createExpense(['trip_id' => $trip->id]);
        $this->postJson('/api/expenses', $this->expense(['trip_id' => $other->id]))->assertForbidden();
        app(AssignmentAdministration::class)->release($admin, $trip->id, 'driver', 'Liberación manual');
        $this->postJson('/api/expenses', $this->expense(['trip_id' => $trip->id]))->assertForbidden();
        $this->getJson('/api/expenses/'.$record['expense_id'])->assertOk();
        $driver->roles()->detach();
        $this->getJson('/api/expenses')->assertForbidden();
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_receipts_are_private_real_content_checked_and_idempotent(): void
    {
        [$admin, $trip, $driver] = $this->prepared();
        $record = $this->actingAs($driver)->createExpense(['trip_id' => $trip->id]);
        $url = '/api/expenses/'.$record['expense_id'].'/receipts';
        $context = $this->getJson('/api/transport/my-trips/'.$trip->id)->assertOk()->json('data.offline_context');
        $key = (string) Str::uuid();
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        $upload = fn () => ['request_key' => $key, 'expected_trip_context' => $context, 'file' => UploadedFile::fake()->createWithContent('recibo.pdf', $pdf)];
        $receipt = $this->post($url, $upload(), ['Accept' => 'application/json'])->assertCreated()->json('data.receipt_id');
        $this->post($url, $upload(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.receipt_id', $receipt);
        $this->get('/api/expenses/receipts/'.$receipt.'/download')->assertOk();
        $this->getJson('/api/expenses/'.$record['expense_id'])->assertOk()->assertDontSee('storage_key')->assertDontSee('/storage/');
        foreach (['jpg', 'png'] as $extension) {
            $this->post($url, ['request_key' => (string) Str::uuid(), 'file' => new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.'.$extension, 'recibo.'.$extension, null, null, true)], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post($url, ['request_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('recibo.pdf', 'texto sin PDF')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['request_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('grande.pdf', 10001, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        app(TripOperations::class)->start($admin, $trip->id, 'Cambio durante desconexión del comprobante');
        $this->post($url, [...$upload(), 'request_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertConflict();
        $this->post($url, $upload(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.receipt_id', $receipt);
        app(TransportAdministration::class)->tripAction($admin, $trip->id, 'cancel', ['version' => $trip->refresh()->version, 'reason' => 'Cancelación previa a liberación manual']);
        app(AssignmentAdministration::class)->release($admin, $trip->id, 'driver', 'Liberación manual posterior al comprobante');
        $this->post($url, $upload(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.receipt_id', $receipt);
        $this->post($url, ['request_key' => (string) Str::uuid(), 'file' => $upload()['file']], ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/api/expenses/receipts/'.$receipt.'/download')->assertOk();
        $this->actingAs($this->actor('driver'))->getJson('/api/expenses/receipts/'.$receipt.'/download')->assertNotFound();
        $this->actingAs($this->actor('mechanic'))->getJson('/api/expenses/receipts/'.$receipt.'/download')->assertForbidden();
        $this->assertDatabaseCount('expense_receipts', 3);
    }

    public function test_sql_rejects_wrong_history_links_and_inexact_money_even_without_http(): void
    {
        $this->prepared();
        $first = $this->createExpense();
        $second = $this->createExpense();
        $original = (array) DB::table('expense_revisions')->where('id', $first['revision_id'])->first();
        foreach (['0', '-1.00', '0.001'] as $amount) {
            $this->rejected(fn () => DB::table('expense_revisions')->insert(array_replace($original, [
                'id' => (string) Str::uuid(), 'supersedes_id' => $first['revision_id'], 'reason' => 'Corrección SQL inválida', 'amount' => $amount,
            ])));
        }
        $this->rejected(fn () => DB::table('expense_revisions')->insert(array_replace($original, [
            'id' => (string) Str::uuid(), 'expense_id' => $second['expense_id'], 'supersedes_id' => $first['revision_id'], 'reason' => 'Historial de otro gasto',
        ])));
        $this->rejected(function () use ($original, $first, $second): void {
            $id = (string) Str::uuid();
            DB::table('expense_revisions')->insert(array_replace($original, ['id' => $id,
                'supersedes_id' => $first['revision_id'], 'reason' => 'Corrección con recibo incompatible']));
            DB::table('expense_operations')->insert(['id' => (string) Str::uuid(), 'actor_id' => $original['recorded_by_id'],
                'scope' => 'correction:'.$first['expense_id'], 'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64),
                'revision_id' => $id, 'result' => json_encode(['expense_id' => $second['expense_id'], 'revision_id' => $id])]);
        });
        $this->assertDatabaseCount('expense_revisions', 2);
        $this->assertDatabaseCount('expense_operations', 2);
    }
}
