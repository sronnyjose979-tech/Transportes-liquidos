<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Application\AccountAdministration;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class AccessAdministrationTest extends PostgresTestCase
{
    private function account(string $role = 'administrator', bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['login' => 'account-'.$user->id, 'is_active' => $active])->save();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function payload(): array
    {
        return [
            'login' => 'new-account', 'name' => 'Cuenta administrativa',
            'email' => 'new@example.test', 'password' => 'Safe-password-2026!',
            'password_confirmation' => 'Safe-password-2026!', 'is_active' => true,
            'roles' => [Role::firstOrCreate(['name' => 'driver'])->id],
        ];
    }

    public function test_administrator_can_manage_accounts_without_exposing_passwords(): void
    {
        $this->actingAs($this->account());
        $payload = $this->payload();
        $response = $this->postJson('/api/users', $payload)->assertCreated()
            ->assertJsonPath('data.login', 'new-account')
            ->assertJsonPath('data.roles.0.name', 'driver')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $id = $response->json('data.id');
        $user = User::findOrFail($id);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertNotSame($payload['password'], $user->password);
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/users/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson('/api/users/'.$id, ['name' => 'Nombre actualizado'])
            ->assertOk()->assertJsonPath('data.name', 'Nombre actualizado');
        $this->assertSame('new-account', $user->refresh()->login);
        $this->assertSame('driver', $user->roles()->first()->name);
        $this->patchJson('/api/users/'.$id, [
            'password' => 'Changed-password-2026!', 'password_confirmation' => 'Changed-password-2026!',
            'roles' => [],
        ])->assertOk();
        $this->assertTrue(Hash::check('Changed-password-2026!', $user->refresh()->password));
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_account_validation_and_unique_fields_do_not_create_partial_accounts(): void
    {
        $this->actingAs($this->account());
        $payload = $this->payload();
        $count = User::count();
        $this->postJson('/api/users', array_replace($payload, ['roles' => [Str::uuid()->toString()]]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->assertSame($count, User::count());
        $this->postJson('/api/users', array_replace($payload, ['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $payload)->assertCreated();
        $this->postJson('/api/users', $payload)->assertUnprocessable()->assertJsonValidationErrors(['login', 'email']);
        $this->assertSame($count + 1, User::count());
        $this->getJson('/api/users?per_page=1000')->assertUnprocessable();
    }

    public function test_protected_fields_cannot_be_mass_assigned_and_activation_has_separate_endpoint(): void
    {
        $this->actingAs($this->account());
        $payload = $this->payload();
        $payload['id'] = 99999999;
        $payload['remember_token'] = 'injected';
        $payload['email_verified_at'] = '2026-01-01';
        $count = User::count();
        $this->postJson('/api/users', $payload)->assertUnprocessable();
        $this->assertSame($count, User::count());
        unset($payload['id'], $payload['remember_token'], $payload['email_verified_at']);
        $id = $this->postJson('/api/users', $payload)->assertCreated()->json('data.id');
        $user = User::findOrFail($id);
        $this->assertNotSame(99999999, $id);
        $this->assertNotSame('injected', $user->remember_token);
        $this->assertNull($user->email_verified_at);
        $this->patchJson('/api/users/'.$id, ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertTrue($user->refresh()->is_active);
        $this->patchJson('/api/users/'.$id.'/activation', ['is_active' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/users/'.$id.'/activation', ['is_active' => false])->assertOk();
        $this->assertFalse($user->refresh()->is_active);
        $this->patchJson('/api/users/'.$id.'/activation', ['is_active' => true])->assertOk();
        $this->assertTrue($user->refresh()->is_active);
        $this->patchJson('/api/users/'.$id.'/activation', ['is_active' => false])->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['login' => $user->login, 'password' => $payload['password']])->assertUnprocessable();
    }

    public function test_every_administration_operation_requires_an_active_administrator(): void
    {
        $target = $this->account('driver');
        $employee = Employee::create(['name' => 'Trabajador', 'is_active' => true]);
        $operations = [
            ['GET', '/api/users', []], ['POST', '/api/users', []],
            ['GET', '/api/users/'.$target->id, []], ['PATCH', '/api/users/'.$target->id, ['roles' => []]],
            ['PATCH', '/api/users/'.$target->id.'/activation', ['is_active' => false]],
            ['GET', '/api/employees', []], ['POST', '/api/employees', []],
            ['GET', '/api/employees/'.$employee->id, []], ['PATCH', '/api/employees/'.$employee->id, ['name' => 'Changed']],
        ];
        foreach ($operations as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertUnauthorized();
        }
        foreach (['secretary', 'driver', 'mechanic'] as $role) {
            $this->actingAs($this->account($role));
            foreach ($operations as [$method, $url, $body]) {
                $this->json($method, $url, $body)->assertForbidden();
            }
        }
        foreach ($operations as [$method, $url, $body]) {
            $this->actingAs($this->account(active: false));
            $this->json($method, $url, $body)->assertForbidden();
        }
        $this->assertTrue($target->refresh()->is_active);
        $this->assertSame('Trabajador', $employee->refresh()->name);
    }

    public function test_administrator_can_manage_employees_with_optional_unique_account_link(): void
    {
        $this->actingAs($this->account());
        $user = $this->account('driver');
        $id = $this->postJson('/api/employees', ['name' => 'Conductor', 'is_active' => true, 'user_id' => $user->id])
            ->assertCreated()->assertJsonPath('data.user_id', $user->id)->json('data.id');
        $this->assertTrue(Str::isUuid($id));
        $this->getJson('/api/employees/'.$id)->assertOk()->assertJsonMissingPath('data.user.password');
        $this->getJson('/api/employees')->assertOk();
        $this->patchJson('/api/employees/'.$id, ['name' => 'Nombre actualizado'])->assertOk();
        $employee = Employee::findOrFail($id);
        $this->assertSame($user->id, $employee->user_id);
        $this->assertTrue($employee->is_active);
        $this->postJson('/api/employees', ['name' => 'Duplicado', 'is_active' => true, 'user_id' => $user->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->patchJson('/api/employees/'.$id, ['user_id' => null, 'is_active' => false])->assertOk();
        $this->assertNull($employee->refresh()->user_id);
        $this->assertFalse($employee->is_active);
        $this->assertTrue($user->refresh()->is_active);
        $this->postJson('/api/employees', ['name' => 'Sin cuenta', 'is_active' => true])->assertCreated();
        $this->postJson('/api/employees', ['name' => 'Segundo sin cuenta', 'is_active' => true])->assertCreated();
    }

    public function test_invalid_employee_inputs_and_links_are_rejected(): void
    {
        $this->actingAs($this->account());
        $this->postJson('/api/employees', ['name' => '', 'is_active' => 'invalid', 'user_id' => 99999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'is_active', 'user_id']);
        $this->getJson('/api/employees/not-uuid')->assertNotFound();
        $this->getJson('/api/users/99999999')->assertNotFound();
        $this->assertSame(0, Employee::count());
    }

    public function test_only_existing_supported_distinct_roles_can_be_assigned(): void
    {
        $admin = $this->account();
        $this->actingAs($admin);
        $payload = $this->payload();
        $this->getJson('/api/roles')->assertOk();
        $unsupported = Role::create(['name' => 'unsupported']);
        $this->postJson('/api/users', array_replace($payload, ['roles' => [$unsupported->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->postJson('/api/users', array_replace($payload, ['roles' => [$payload['roles'][0], $payload['roles'][0]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->assertSame(0, Employee::count());
        $this->actingAs($this->account('secretary'))->getJson('/api/roles')->assertForbidden();
    }

    public function test_administrative_writes_require_csrf_in_real_requests(): void
    {
        $this->actingAs($this->account());
        $this->app['env'] = 'local';
        $this->postJson('/api/users', [])->assertStatus(419);
        $this->postJson('/api/employees', [])->assertStatus(419);
    }

    public function test_malformed_passwords_and_oversized_account_ids_fail_safely(): void
    {
        $this->actingAs($this->account());
        $payload = $this->payload();
        $this->postJson('/api/users', array_replace($payload, ['password' => ['invalid']]))->assertUnprocessable();
        $this->postJson('/api/users', array_replace($payload, ['password' => str_repeat('á', 40), 'password_confirmation' => str_repeat('á', 40)]))->assertUnprocessable();
        $this->getJson('/api/users/9999999999999999999999999')->assertNotFound();
        $this->patchJson('/api/users/9999999999999999999999999', ['name' => 'Invalid'])->assertNotFound();
    }

    public function test_self_deactivation_invalidates_current_session(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->patchJson('/api/users/'.$admin->id.'/activation', ['is_active' => false])->assertOk();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_boolean_zero_deactivation_removes_persisted_sessions(): void
    {
        $admin = $this->account();
        $target = $this->account('driver');
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'old-target-session', 'user_id' => $target->id,
            'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
        $this->actingAs($admin)->patchJson('/api/users/'.$target->id.'/activation', ['is_active' => 0])->assertOk();
        $this->assertFalse($target->refresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-target-session']);
        $this->patchJson('/api/users/'.$target->id.'/activation', ['is_active' => true])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-target-session']);
    }

    public function test_password_change_invalidates_a_previously_authenticated_session(): void
    {
        $user = $this->account('driver');
        $oldHash = auth('web')->hashPasswordForCookie($user->password);
        $user->forceFill(['password' => 'Changed-password-2026!'])->save();
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash]);
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_write_rechecks_stale_administrator_state_before_changes(): void
    {
        $admin = $this->account();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $before = User::count();
        try {
            app(AccountAdministration::class)->save($admin, $this->payload());
            $this->fail('Una cuenta desactivada no debe crear usuarios.');
        } catch (AuthorizationException $exception) {
            $this->assertSame($before, User::count());
        }
    }
}
