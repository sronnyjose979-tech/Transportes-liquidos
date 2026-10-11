<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class FleetApiTest extends PostgresTestCase
{
    private function account(string $role = 'administrator', bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['login' => 'fleet-'.$user->id, 'is_active' => $active])->save();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function tanker(string $code = 'TANKER-001', bool $partitioned = false): array
    {
        $compartments = [['code' => 'A', 'capacity_liters' => '1234.50', 'is_active' => true]];
        if ($partitioned) {
            $compartments[] = ['code' => 'B', 'capacity_liters' => '9876.54', 'is_active' => false];
        }

        return ['code' => $code, 'has_physical_partitions' => $partitioned, 'compartments' => $compartments];
    }

    public function test_administrator_creates_and_reads_tractor_with_shared_uuid(): void
    {
        $this->actingAs($this->account());
        $response = $this->postJson('/api/tractor-units', ['code' => 'TRACTOR-001'])->assertCreated()
            ->assertJsonPath('data.code', 'TRACTOR-001')->assertJsonPath('data.type', 'tractor_unit')
            ->assertJsonPath('data.service_status', 'available');
        $id = $response->json('data.id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertDatabaseHas('fleet_assets', ['id' => $id, 'code' => 'TRACTOR-001', 'type' => 'tractor_unit']);
        $this->assertDatabaseHas('tractor_units', ['fleet_asset_id' => $id]);
        $this->getJson('/api/tractor-units/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/tankers/'.$id)->assertNotFound();
    }

    public function test_tanker_creation_persists_exact_capacities_and_compartment_states(): void
    {
        $this->actingAs($this->account());
        foreach ([false, true] as $partitioned) {
            $payload = $this->tanker('TANKER-'.(int) $partitioned, $partitioned);
            $response = $this->postJson('/api/tankers', $payload)->assertCreated()
                ->assertJsonPath('data.type', 'tanker')->assertJsonPath('data.service_status', 'available')
                ->assertJsonPath('data.total_capacity_liters', $partitioned ? '11111.04' : '1234.50')
                ->assertJsonPath('data.has_physical_partitions', $partitioned)
                ->assertJsonCount($partitioned ? 2 : 1, 'data.compartments');
            $id = $response->json('data.id');
            $this->assertTrue(Str::isUuid($id));
            $this->assertDatabaseHas('tankers', ['fleet_asset_id' => $id, 'has_physical_partitions' => $partitioned]);
            $compartments = collect($response->json('data.compartments'))->keyBy('code');
            foreach ($payload['compartments'] as $compartment) {
                $stored = $compartments[$compartment['code']];
                $this->assertTrue(Str::isUuid($stored['id']));
                $this->assertSame($id, $stored['tanker_id']);
                $this->assertSame($compartment['capacity_liters'], $stored['capacity_liters']);
                $this->assertSame($compartment['is_active'], $stored['is_active']);
            }
            $this->getJson('/api/tankers/'.$id)->assertOk()->assertJsonPath('data.id', $id);
            $this->getJson('/api/tractor-units/'.$id)->assertNotFound();
        }
    }

    public function test_codes_are_unique_across_all_fleet_assets_and_per_tanker(): void
    {
        $this->actingAs($this->account());
        $this->postJson('/api/tractor-units', ['code' => 'SHARED'])->assertCreated();
        $this->postJson('/api/tractor-units', ['code' => 'SHARED'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/tankers', $this->tanker('SHARED'))->assertUnprocessable()->assertJsonValidationErrors('code');
        $first = $this->postJson('/api/tankers', $this->tanker('FIRST'))->assertCreated()->json('data.id');
        $this->postJson('/api/tankers', $this->tanker('SECOND'))->assertCreated();
        $this->assertDatabaseHas('compartments', ['tanker_id' => $first, 'code' => 'A']);
        $duplicate = $this->tanker('DUPLICATE', true);
        $duplicate['compartments'][1]['code'] = 'A';
        $this->postJson('/api/tankers', $duplicate)->assertUnprocessable();
        $this->assertDatabaseMissing('fleet_assets', ['code' => 'DUPLICATE']);
    }

    public function test_invalid_creation_and_partition_cardinality_leave_no_partial_assets(): void
    {
        $this->actingAs($this->account());
        $this->postJson('/api/tractor-units', [])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/tractor-units', ['code' => ''])->assertUnprocessable();
        $this->postJson('/api/tractor-units', ['code' => 'PROTECTED', 'service_status' => 'maintenance', 'type' => 'tanker'])->assertUnprocessable();
        foreach ([[false, []], [true, []], [false, $this->tanker('unused', true)['compartments']]] as [$partitioned, $compartments]) {
            $this->postJson('/api/tankers', ['code' => 'INVALID', 'has_physical_partitions' => $partitioned, 'compartments' => $compartments])->assertUnprocessable();
        }
        $this->postJson('/api/tankers', ['code' => 'INVALID', 'compartments' => $this->tanker()['compartments']])->assertUnprocessable();
        $this->assertSame(0, DB::table('fleet_assets')->count());
        $this->assertSame(0, DB::table('tankers')->count());
        $this->assertSame(0, DB::table('compartments')->count());
        $partitioned = $this->tanker('ONE-PHYSICAL');
        $partitioned['has_physical_partitions'] = true;
        $this->postJson('/api/tankers', $partitioned)->assertCreated()->assertJsonCount(1, 'data.compartments');
    }

    public function test_capacity_requires_positive_exact_decimal_and_valid_compartment_fields(): void
    {
        $this->actingAs($this->account());
        foreach (['0', '-1.00', '1.001', '10000000000.00', '1e3', 'invalid', null, []] as $capacity) {
            $payload = $this->tanker();
            $payload['compartments'][0]['capacity_liters'] = $capacity;
            $this->postJson('/api/tankers', $payload)->assertUnprocessable()->assertJsonValidationErrors('compartments.0.capacity_liters');
        }
        foreach ([['code' => ''], ['is_active' => 'invalid'], ['unexpected' => true]] as $invalid) {
            $payload = $this->tanker();
            $payload['compartments'][0] = array_replace($payload['compartments'][0], $invalid);
            $this->postJson('/api/tankers', $payload)->assertUnprocessable();
        }
        $payload = $this->tanker();
        unset($payload['compartments'][0]['is_active']);
        $this->postJson('/api/tankers', $payload)->assertUnprocessable()->assertJsonValidationErrors('compartments.0.is_active');
        $this->assertSame(0, DB::table('fleet_assets')->count());
        $payload = $this->tanker('MAXIMUM');
        $payload['compartments'][0]['capacity_liters'] = '9999999999.99';
        $this->postJson('/api/tankers', $payload)->assertCreated()->assertJsonPath('data.compartments.0.capacity_liters', '9999999999.99');
        $payload = $this->tanker('MINIMUM');
        $payload['compartments'][0]['capacity_liters'] = '0.01';
        $this->postJson('/api/tankers', $payload)->assertCreated()->assertJsonPath('data.compartments.0.capacity_liters', '0.01');
    }

    public function test_lists_are_paginated_and_include_only_corresponding_asset_type(): void
    {
        $this->actingAs($this->account());
        for ($index = 0; $index < 3; $index++) {
            $this->postJson('/api/tractor-units', ['code' => 'TRACTOR-'.$index])->assertCreated();
            $this->postJson('/api/tankers', $this->tanker('TANKER-'.$index))->assertCreated();
        }
        foreach (['tractor-units', 'tankers'] as $resource) {
            $this->getJson('/api/'.$resource.'?per_page=2')->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2);
            $this->getJson('/api/'.$resource.'?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson('/api/'.$resource.'?per_page=1000')->assertUnprocessable();
            $this->getJson('/api/'.$resource.'/not-uuid')->assertNotFound();
            $this->getJson('/api/'.$resource.'/'.Str::uuid())->assertNotFound();
        }
    }

    public function test_updates_codes_without_changing_protected_asset_fields(): void
    {
        $this->actingAs($this->account());
        $tractor = $this->postJson('/api/tractor-units', ['code' => 'TRACTOR'])->assertCreated()->json('data.id');
        $tanker = $this->postJson('/api/tankers', $this->tanker('TANKER', true))->assertCreated()->json('data');
        $this->patchJson('/api/tractor-units/'.$tractor, ['code' => 'TRACTOR'])->assertOk();
        $this->patchJson('/api/tractor-units/'.$tractor, ['code' => 'RENAMED'])->assertOk()->assertJsonPath('data.code', 'RENAMED');
        $this->patchJson('/api/tankers/'.$tanker['id'], ['code' => 'RENAMED'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/tankers/'.$tanker['id'], ['code' => 'TANKER-RENAMED'])->assertOk()->assertJsonPath('data.code', 'TANKER-RENAMED');
        foreach ([['type' => 'tanker'], ['service_status' => 'maintenance'], ['is_active' => false], ['id' => (string) Str::uuid()]] as $protected) {
            $this->patchJson('/api/tractor-units/'.$tractor, $protected)->assertUnprocessable();
        }
        foreach ([['has_physical_partitions' => false], ['service_status' => 'maintenance'], ['compartments' => [['id' => $tanker['compartments'][0]['id'], 'is_active' => false]]]] as $protected) {
            $this->patchJson('/api/tankers/'.$tanker['id'], $protected)->assertUnprocessable();
        }
        $this->assertDatabaseHas('fleet_assets', ['id' => $tractor, 'code' => 'RENAMED', 'type' => 'tractor_unit', 'service_status' => 'available']);
        $this->assertDatabaseHas('tankers', ['fleet_asset_id' => $tanker['id'], 'has_physical_partitions' => true]);
    }

    public function test_compartment_code_updates_are_scoped_unique_and_atomic(): void
    {
        $this->actingAs($this->account());
        $first = $this->postJson('/api/tankers', $this->tanker('FIRST', true))->assertCreated()->json('data');
        $second = $this->postJson('/api/tankers', $this->tanker('SECOND'))->assertCreated()->json('data');
        $byCode = collect($first['compartments'])->keyBy('code');
        $id = $byCode['A']['id'];
        $this->patchJson('/api/tankers/'.$first['id'], ['compartments' => [['id' => $id, 'code' => 'A']]])->assertOk();
        $this->patchJson('/api/tankers/'.$first['id'], ['compartments' => [['id' => $id, 'code' => 'RENAMED']]])->assertOk();
        $this->assertDatabaseHas('compartments', ['id' => $id, 'code' => 'RENAMED', 'capacity_liters' => '1234.50', 'is_active' => true]);
        $this->assertDatabaseHas('compartments', ['id' => $byCode['B']['id'], 'code' => 'B']);
        $this->patchJson('/api/tankers/'.$first['id'], ['code' => 'ROLLBACK', 'compartments' => [['id' => $id, 'code' => 'B']]])->assertUnprocessable();
        $this->assertDatabaseHas('fleet_assets', ['id' => $first['id'], 'code' => 'FIRST']);
        $this->patchJson('/api/tankers/'.$first['id'], ['compartments' => [['id' => $second['compartments'][0]['id'], 'code' => 'FOREIGN']]])->assertUnprocessable();
        $this->patchJson('/api/tankers/'.$first['id'], ['compartments' => [['code' => 'NEW', 'capacity_liters' => '100.00']]])->assertUnprocessable();
        $this->patchJson('/api/tankers/'.$first['id'], ['compartments' => [['id' => $id, 'code' => 'ONE'], ['id' => $id, 'code' => 'TWO']]])->assertUnprocessable();
        $this->assertSame(2, DB::table('compartments')->where('tanker_id', $first['id'])->count());
    }

    public function test_capacity_updates_preserve_exact_precision_and_recalculate_total(): void
    {
        $this->actingAs($this->account());
        $payload = $this->tanker('CAPACITY', true);
        $payload['compartments'][0]['capacity_liters'] = '1000.10';
        $payload['compartments'][1]['capacity_liters'] = '2000.20';
        $tanker = $this->postJson('/api/tankers', $payload)->assertCreated()
            ->assertJsonPath('data.total_capacity_liters', '3000.30')->json('data');
        $byCode = collect($tanker['compartments'])->keyBy('code');
        $this->patchJson('/api/tankers/'.$tanker['id'], ['compartments' => [
            ['id' => $byCode['A']['id'], 'capacity_liters' => '1000.11'],
        ]])->assertOk()->assertJsonPath('data.total_capacity_liters', '3000.31');
        $this->assertDatabaseHas('compartments', ['id' => $byCode['A']['id'], 'capacity_liters' => '1000.11']);
        $this->assertDatabaseHas('compartments', ['id' => $byCode['B']['id'], 'capacity_liters' => '2000.20', 'is_active' => false]);
        foreach (['0.00', '-0.01', '0.001', '10000000000.00'] as $invalid) {
            $this->patchJson('/api/tankers/'.$tanker['id'], ['code' => 'ROLLBACK', 'compartments' => [
                ['id' => $byCode['A']['id'], 'capacity_liters' => $invalid],
            ]])->assertUnprocessable();
        }
        $this->assertDatabaseHas('fleet_assets', ['id' => $tanker['id'], 'code' => 'CAPACITY']);
        $this->getJson('/api/tankers/'.$tanker['id'])->assertOk()->assertJsonPath('data.total_capacity_liters', '3000.31');
        $this->patchJson('/api/tankers/'.$tanker['id'], ['total_capacity_liters' => '1.00'])->assertUnprocessable();
    }

    public function test_fleet_writes_require_csrf_for_real_requests(): void
    {
        $this->actingAs($this->account());
        $this->app['env'] = 'local';
        $this->postJson('/api/tractor-units', ['code' => 'CSRF'])->assertStatus(419);
        $this->postJson('/api/tankers', $this->tanker('CSRF'))->assertStatus(419);
        $this->assertSame(0, DB::table('fleet_assets')->count());
    }

    public function test_fleet_operations_require_authentication_and_active_administrator(): void
    {
        $id = (string) Str::uuid();
        $operations = [];
        foreach (['tractor-units', 'tankers'] as $resource) {
            $operations[] = ['GET', '/api/'.$resource, []];
            $operations[] = ['POST', '/api/'.$resource, []];
            $operations[] = ['GET', '/api/'.$resource.'/'.$id, []];
            $operations[] = ['PATCH', '/api/'.$resource.'/'.$id, []];
        }
        foreach ($operations as [$method, $url, $payload]) {
            $this->json($method, $url, $payload)->assertUnauthorized();
        }
        foreach (['driver', 'secretary', 'mechanic'] as $role) {
            $this->actingAs($this->account($role));
            foreach ($operations as [$method, $url, $payload]) {
                $this->json($method, $url, $payload)->assertForbidden();
            }
        }
        foreach ($operations as [$method, $url, $payload]) {
            $this->actingAs($this->account(active: false))->json($method, $url, $payload)->assertForbidden();
        }
        $this->assertSame(0, DB::table('fleet_assets')->count());
    }
}
