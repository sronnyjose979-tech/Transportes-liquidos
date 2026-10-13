<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use Tests\PostgresTestCase;

class AdministrationIntegrationTest extends PostgresTestCase
{
    private function account(string $role = 'administrator'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    public function test_fleet_created_and_edited_by_administration_is_visible_to_documentation_and_transport(): void
    {
        $this->actingAs($this->account());
        $tractor = $this->postJson('/api/tractor-units', ['code' => 'ADMIN-HEAD'])->assertCreated()->json('data.id');
        $tanker = $this->postJson('/api/tankers', [
            'code' => 'ADMIN-TANK', 'has_physical_partitions' => true,
            'compartments' => [
                ['code' => 'A', 'capacity_liters' => '2500.50', 'is_active' => true],
                ['code' => 'B', 'capacity_liters' => '1250.25', 'is_active' => true],
            ],
        ])->assertCreated()->json('data');
        $compartment = $tanker['compartments'][0];
        $this->patchJson('/api/tractor-units/'.$tractor, ['code' => 'ADMIN-HEAD-EDITED'])->assertOk();
        $this->patchJson('/api/tankers/'.$tanker['id'], [
            'code' => 'ADMIN-TANK-EDITED',
            'compartments' => [['id' => $compartment['id'], 'code' => 'FRONT', 'capacity_liters' => '2500.75']],
        ])->assertOk()->assertJsonPath('data.total_capacity_liters', '3751.00');
        $documents = collect($this->getJson('/api/fleet-assets?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('ADMIN-HEAD-EDITED', $documents[$tractor]['code']);
        $this->assertSame('ADMIN-TANK-EDITED', $documents[$tanker['id']]['code']);
        $transport = collect($this->getJson('/api/transport/lookups/assets?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('tractor_unit', $transport[$tractor]['type']);
        $this->assertSame('ADMIN-TANK-EDITED', $transport[$tanker['id']]['code']);
        $front = collect($transport[$tanker['id']]['compartments'])->firstWhere('id', $compartment['id']);
        $this->assertSame('FRONT', $front['code']);
        $this->assertSame('2500.75', $front['capacity_liters']);
    }

    public function test_linked_driver_follows_employee_account_role_and_activation_changes_in_transport(): void
    {
        $this->actingAs($this->account());
        $driverRole = Role::firstOrCreate(['name' => 'driver']);
        $mechanicRole = Role::firstOrCreate(['name' => 'mechanic']);
        $user = $this->postJson('/api/users', [
            'login' => 'administration-driver', 'name' => 'Cuenta de conductor',
            'email' => 'administration-driver@example.test', 'is_active' => true,
            'password' => 'Synthetic-account-42', 'password_confirmation' => 'Synthetic-account-42',
            'roles' => [$driverRole->id],
        ])->assertCreated()->json('data');
        $employee = $this->postJson('/api/employees', ['name' => 'Conductor registrado', 'is_active' => true, 'user_id' => $user['id']])
            ->assertCreated()->json('data');
        $this->getJson('/api/transport/lookups/drivers')->assertOk()->assertJsonFragment(['id' => $employee['id'], 'name' => 'Conductor registrado']);
        $this->patchJson('/api/employees/'.$employee['id'], ['name' => 'Conductor corregido'])->assertOk();
        $this->getJson('/api/transport/lookups/drivers')->assertOk()->assertJsonFragment(['name' => 'Conductor corregido']);
        $this->patchJson('/api/users/'.$user['id'].'/activation', ['is_active' => false])->assertOk();
        $this->getJson('/api/transport/lookups/drivers')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/users/'.$user['id'].'/activation', ['is_active' => true])->assertOk();
        $this->patchJson('/api/users/'.$user['id'], ['roles' => [$mechanicRole->id]])->assertOk();
        $this->getJson('/api/transport/lookups/drivers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/users/'.$user['id'])->assertOk()->assertJsonPath('data.roles.0.name', 'mechanic');
        $this->getJson('/api/employees/'.$employee['id'])->assertOk()->assertJsonPath('data.user_id', (int) $user['id']);
        $this->patchJson('/api/users/'.$user['id'], ['roles' => [$driverRole->id]])->assertOk();
        $this->getJson('/api/transport/lookups/drivers')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/employees/'.$employee['id'], ['is_active' => false])->assertOk();
        $this->getJson('/api/transport/lookups/drivers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_catalog_changes_are_used_by_operational_selectors_and_inactive_products_are_excluded(): void
    {
        $this->actingAs($this->account());
        foreach ([
            ['customers', 'customers', ['name' => 'Cliente registrado'], ['name' => 'Cliente corregido']],
            ['liquid-products', 'products', ['name' => 'Producto registrado', 'is_active' => true], ['name' => 'Producto corregido', 'is_active' => true]],
            ['operation-sites', 'sites', ['name' => 'Sitio registrado', 'address' => 'Dirección sintética'], ['name' => 'Sitio corregido', 'address' => 'Dirección corregida']],
        ] as [$catalog, $lookup, $created, $updated]) {
            $id = $this->postJson('/api/catalogs/'.$catalog, $created)->assertCreated()->json('data.id');
            $this->putJson('/api/catalogs/'.$catalog.'/'.$id, $updated)->assertOk();
            $this->getJson('/api/transport/lookups/'.$lookup)->assertOk()->assertJsonFragment(['id' => $id, 'name' => $updated['name']]);
            if ($catalog === 'liquid-products') {
                $this->putJson('/api/catalogs/'.$catalog.'/'.$id, ['name' => $updated['name'], 'is_active' => false])->assertOk();
                $this->getJson('/api/transport/lookups/products')->assertOk()->assertJsonCount(0, 'data');
            }
        }
    }

    public function test_invalid_administration_inputs_do_not_create_resources(): void
    {
        $this->actingAs($this->account());
        $this->postJson('/api/tankers', ['code' => 'INVALID-ADMIN', 'has_physical_partitions' => false, 'compartments' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('compartments');
        $this->assertDatabaseMissing('fleet_assets', ['code' => 'INVALID-ADMIN']);
        $this->postJson('/api/employees', ['name' => 'Sin cuenta válida', 'is_active' => true, 'user_id' => 999999999])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/users', ['login' => 'invalid-admin', 'name' => 'Inválido', 'email' => 'invalid@example.test', 'password' => 'short', 'password_confirmation' => 'different', 'roles' => [], 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/catalogs/operation-sites', ['name' => 'Sin dirección'])
            ->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->assertDatabaseMissing('employees', ['name' => 'Sin cuenta válida']);
        $this->assertDatabaseMissing('users', ['login' => 'invalid-admin']);
        $this->assertDatabaseMissing('operation_sites', ['name' => 'Sin dirección']);
    }

    public function test_non_administrators_cannot_access_or_write_administration_resources(): void
    {
        foreach (['secretary', 'mechanic', 'driver'] as $role) {
            $this->actingAs($this->account($role));
            foreach (['tractor-units', 'tankers', 'users', 'employees', 'roles', 'catalogs/customers', 'catalogs/liquid-products', 'catalogs/operation-sites'] as $resource) {
                $this->getJson('/api/'.$resource)->assertForbidden();
                if ($resource !== 'roles') {
                    $this->postJson('/api/'.$resource, [])->assertForbidden();
                }
            }
        }
        $admin = $this->account();
        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $admin->roles()->detach();
        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/tractor-units', ['code' => 'REVOKED-ADMIN'])->assertForbidden();
        $this->assertDatabaseMissing('fleet_assets', ['code' => 'REVOKED-ADMIN']);
    }
}
