<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\TransportAndDispatch\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class BackendTest extends PostgresTestCase
{
    private function account(string $role = 'administrator', bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['login' => 'login-'.$user->id, 'is_active' => $active])->save();
        $user->roles()->attach(Role::create(['name' => $role]));

        return $user;
    }

    public function test_login_session_and_logout(): void
    {
        $user = $this->account();
        $this->postJson('/api/login', ['login' => $user->login, 'password' => 'password'])->assertOk()->assertJsonPath('data.login', $user->login)->assertJsonMissingPath('data.password');
        $this->getJson('/api/session')->assertOk()->assertJsonPath('data.roles.0', 'administrator');
        $this->postJson('/api/logout')->assertNoContent();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_invalid_and_inactive_credentials_are_rejected(): void
    {
        $user = $this->account(active: false);
        $this->postJson('/api/login', ['login' => $user->login, 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->postJson('/api/login', ['login' => 'unknown', 'password' => 'wrong'])->assertUnprocessable();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['login' => 'unknown', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['login' => 'unknown', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_anonymous_and_non_admin_cannot_manage_catalogs(): void
    {
        $this->getJson('/api/catalogs/customers')->assertUnauthorized();
        foreach (['secretary', 'driver', 'mechanic'] as $role) {
            $this->actingAs($this->account($role))->postJson('/api/catalogs/customers', ['name' => 'Cliente'])->assertForbidden();
        }
        $this->assertSame(0, Customer::count());
    }

    public function test_deactivated_session_loses_access(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save();
        $this->getJson('/api/catalogs/customers')->assertForbidden();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_admin_can_create_read_update_and_list_all_catalogs(): void
    {
        $this->actingAs($this->account());
        $catalogs = ['customers' => ['name' => 'Cliente'], 'liquid-products' => ['name' => 'Agua', 'is_active' => true], 'operation-sites' => ['name' => 'Origen', 'address' => 'Dirección'], 'suppliers' => ['name' => 'Proveedor'], 'spare-parts' => ['code' => 'R1', 'description' => 'Repuesto', 'unit' => 'unidad']];
        foreach ($catalogs as $catalog => $data) {
            $url = '/api/catalogs/'.$catalog;
            $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
            $this->assertTrue(Str::isUuid($id));
            $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
            if (isset($data['name'])) {
                $data['name'] .= ' actualizado';
            } else {
                $data['description'] .= ' actualizado';
            }
            $this->putJson($url.'/'.$id, $data)->assertOk();
            $field = isset($data['name']) ? 'name' : 'description';
            $updated = $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.'.$field, $data[$field]);
            $this->assertStringContainsString('no-store', $updated->headers->get('Cache-Control'));
            $this->getJson($url.'?per_page=10')->assertOk()->assertJsonPath('total', 1);
        }
    }

    public function test_invalid_input_and_mass_assignment_are_controlled(): void
    {
        $this->actingAs($this->account());
        $this->postJson('/api/catalogs/liquid-products', ['name' => 'Agua'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->getJson('/api/catalogs/customers?per_page=1000')->assertUnprocessable();
        $response = $this->postJson('/api/catalogs/customers', ['name' => 'Cliente', 'id' => '00000000-0000-0000-0000-000000000001']);
        $response->assertCreated();
        $this->assertNotSame('00000000-0000-0000-0000-000000000001', $response->json('data.id'));
        $this->getJson('/api/catalogs/unknown')->assertNotFound();
        $this->getJson('/api/catalogs/customers/not-uuid')->assertNotFound();
    }

    public function test_employee_optional_unique_account_and_relations(): void
    {
        $user = $this->account();
        $employee = Employee::create(['user_id' => $user->id, 'name' => 'Trabajador', 'is_active' => true]);
        $this->assertTrue($employee->user->is($user));
        $this->assertTrue($user->employee->is($employee));
        Employee::create(['name' => 'Sin cuenta', 'is_active' => true]);
        $this->expectException(QueryException::class);
        Employee::create(['user_id' => $user->id, 'name' => 'Duplicado', 'is_active' => true]);
    }

    public function test_role_association_cannot_duplicate(): void
    {
        $user = $this->account();
        $role = $user->roles()->first();
        $this->expectException(QueryException::class);
        $user->roles()->attach($role);
    }

    public function test_role_foreign_key_rejects_unknown_user(): void
    {
        $role = Role::create(['name' => 'driver']);
        $this->expectException(QueryException::class);
        DB::table('user_roles')->insert(['user_id' => 999999999, 'role_id' => $role->id]);
    }

    public function test_catalog_write_rolls_back_atomically(): void
    {
        try {
            DB::transaction(function () {
                Customer::create(['name' => 'No persiste']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertSame(0, Customer::count());
    }

    public function test_real_requests_require_csrf_token(): void
    {
        $this->app['env'] = 'local';
        $this->postJson('/api/login', ['login' => 'unknown', 'password' => 'wrong'])->assertStatus(419);
    }
}
