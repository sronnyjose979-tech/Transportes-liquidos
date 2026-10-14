<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Models\SparePart;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;
use Tests\Support\InventoryFixtures;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class InventoryLedgerTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    private function part(): SparePart
    {
        return SparePart::create(['code' => 'INV-'.Str::uuid(), 'description' => 'Filtro', 'unit' => 'unidad']);
    }

    private function data(SparePart $part, string $kind = 'initial', string $quantity = '2.123456'): array
    {
        return ['request_key' => (string) Str::uuid(), 'spare_part_id' => $part->id, 'kind' => $kind, 'quantity' => $quantity, 'unit_snapshot' => $part->unit, 'reason' => 'Conteo físico sintético confirmado', 'occurred_at' => now()->toIso8601String()];
    }

    public function test_exact_authorized_opening_adjustment_reversal_and_replays(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $secretary = $this->actor('secretary');
        $part = $this->part();
        $data = $this->data($part);
        $id = $this->actingAs($secretary)->postJson('/api/inventory/corrections', $data)->assertCreated()->json('data.correction_id');
        $this->postJson('/api/inventory/corrections', $data)->assertCreated()->assertJsonPath('data.correction_id', $id);
        $this->postJson('/api/inventory/corrections', [...$data, 'quantity' => '3'])->assertConflict();
        $decision = ['request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Conteo revisado', 'occurred_at' => $data['occurred_at']];
        $this->postJson("/api/inventory/corrections/$id/decisions", $decision)->assertForbidden();
        $d = $this->actingAs($admin)->postJson("/api/inventory/corrections/$id/decisions", $decision)->assertCreated()->json('data.decision_id');
        $apply = ['request_key' => (string) Str::uuid(), 'occurred_at' => $data['occurred_at']];
        $this->postJson("/api/inventory/decisions/$d/apply", $apply)->assertCreated();
        $this->postJson("/api/inventory/decisions/$d/apply", $apply)->assertCreated();
        $this->getJson('/api/inventory/stock')->assertOk()->assertJsonPath('data.0.available_quantity', '2.123456');
        $this->getJson('/api/inventory/movements')->assertOk()->assertJsonPath('data.0.actor_name', $admin->name)->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.password');
        $ledger = app(InventoryLedger::class);
        $payload = [...$this->data($part, 'adjustment', '-1'), 'occurred_at' => $data['occurred_at']];
        unset($payload['request_key']);
        $correction = $ledger->correction($admin, (string) Str::uuid(), $payload);
        $approved = $ledger->decide($admin, $correction['correction_id'], (string) Str::uuid(), ['decision' => 'approved', 'reason' => 'Ajuste revisado', 'occurred_at' => $data['occurred_at']]);
        $applied = $ledger->apply($admin, $approved['decision_id'], (string) Str::uuid(), ['occurred_at' => $data['occurred_at']]);
        $reverse = $ledger->correction($admin, (string) Str::uuid(), [...$payload, 'kind' => 'reversal', 'quantity' => '1', 'reverses_movement_id' => $applied['movement_id']]);
        $approved = $ledger->decide($admin, $reverse['correction_id'], (string) Str::uuid(), ['decision' => 'approved', 'reason' => 'Reversión autorizada', 'occurred_at' => $data['occurred_at']]);
        $ledger->apply($admin, $approved['decision_id'], (string) Str::uuid(), ['occurred_at' => $data['occurred_at']]);
        $this->getJson('/api/inventory/stock')->assertOk()->assertJsonPath('data.0.available_quantity', '2.123456');
        $this->getJson('/api/inventory/movements')->assertOk()->assertJsonPath('data.0.actor_name', $admin->name)->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.password');
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_permissions_precision_unknown_actor_and_no_unapproved_stock(): void
    {
        $this->prepareWorkshopSchema();
        $part = $this->part();
        $admin = $this->actor();
        $data = $this->data($part);
        $this->getJson('/api/inventory/stock')->assertUnauthorized();
        foreach (['mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/inventory/stock')->assertForbidden();
            $this->postJson('/api/inventory/corrections', $data)->assertForbidden();
        }
        $this->actingAs($admin)->postJson('/api/inventory/corrections', [...$data, 'quantity' => '1.0000001'])->assertUnprocessable();
        $this->postJson('/api/inventory/corrections', [...$data, 'requested_by_id' => $admin->id])->assertUnprocessable();
        $this->postJson('/api/inventory/corrections', $data)->assertCreated();
        $this->getJson('/api/inventory/stock')->assertOk()->assertJsonPath('data.0.initialized', false)->assertJsonPath('data.0.available_quantity', null);
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin->fresh())->getJson('/api/inventory/stock')->assertForbidden();
    }

    public function test_direct_sql_history_precision_negative_and_unit_protections(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $part = $this->part();
        InventoryFixtures::reconcile($admin, $part, '0');
        $row = (array) DB::table('inventory_corrections')->first();
        foreach ([
            fn () => DB::table('inventory_corrections')->insert([...$row, 'id' => (string) Str::uuid(), 'quantity' => '0.0000001']),
            fn () => DB::table('inventory_movements')->insert([...(array) DB::table('inventory_movements')->first(), 'id' => (string) Str::uuid(), 'kind' => 'receipt', 'warehouse_delta' => '20', 'purchase_receipt_item_id' => null]),
            fn () => DB::table('inventory_movements')->update(['warehouse_delta' => '2']),
            fn () => DB::table('inventory_movements')->delete(),
            fn () => DB::statement('TRUNCATE inventory_movements CASCADE'),
            fn () => DB::table('spare_parts')->where('id', $part->id)->update(['unit' => 'litro']),
        ] as $invalid) {
            try {
                DB::transaction($invalid);
                $this->fail('Escritura inválida aceptada');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
            }
        }
        $ledger = app(InventoryLedger::class);
        $payload = $this->data($part, 'adjustment', '-0.000001');
        unset($payload['request_key']);
        $c = $ledger->correction($admin, (string) Str::uuid(), $payload);
        $d = $ledger->decide($admin, $c['correction_id'], (string) Str::uuid(), ['decision' => 'approved', 'reason' => 'Prueba negativa', 'occurred_at' => $payload['occurred_at']]);
        try {
            $ledger->apply($admin, $d['decision_id'], (string) Str::uuid(), ['occurred_at' => $payload['occurred_at']]);
            $this->fail('Stock negativo aceptado');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_movements', 1);
            $this->assertDatabaseCount('inventory_applications', 1);
        }
    }

    public function test_concurrent_adjustments_cannot_overdraw_the_same_stock(): void
    {
        $this->prepareWorkshopSchema();
        $admin = $this->actor();
        $part = $this->part();
        InventoryFixtures::reconcile($admin, $part, '1');
        $ledger = app(InventoryLedger::class);
        $at = now()->toIso8601String();
        $decisions = [];
        for ($index = 0; $index < 2; $index++) {
            $data = [...$this->data($part, 'adjustment', '-1'), 'occurred_at' => $at];
            unset($data['request_key']);
            $c = $ledger->correction($admin, (string) Str::uuid(), $data);
            $decisions[] = $ledger->decide($admin, $c['correction_id'], (string) Str::uuid(), ['decision' => 'approved', 'reason' => 'Conteo autorizado', 'occurred_at' => $at])['decision_id'];
        }
        $configuration = config('database.connections.pgsql_testing');
        $rival = new \PDO('pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'], $configuration['username'], $configuration['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rival->exec('SET search_path TO '.$this->workshopSchema.',public');
        $rival->exec("SET lock_timeout='150ms'");
        DB::beginTransaction();
        $ledger->apply($admin, $decisions[0], (string) Str::uuid(), ['occurred_at' => $at]);
        $insert = $rival->prepare('INSERT INTO inventory_applications(id,decision_id,applied_by_id,occurred_at) VALUES(?,?,?,?)');
        $parameters = [(string) Str::uuid(), $decisions[1], $admin->id, $at];
        try {
            $insert->execute($parameters);
            $this->fail('Escritura concurrente evadió bloqueo');
        } catch (\PDOException $error) {
            $this->assertSame('55P03', $error->getCode());
        }
        DB::commit();
        try {
            $insert->execute($parameters);
            $this->fail('Existencias negativas aceptadas');
        } catch (\PDOException $error) {
            $this->assertSame('23514', $error->getCode());
        }
        $this->assertSame('0', DB::table('inventory_movements')->where('spare_part_id', $part->id)->selectRaw('sum(warehouse_delta)::text AS balance')->first()->balance);
        $this->assertDatabaseCount('inventory_applications', 2);
    }
}
