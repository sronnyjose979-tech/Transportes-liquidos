<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

class EmployeeAccountLinkTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::create(['name' => 'administrator']));
        $this->actingAs($admin);
    }

    public function test_update_can_keep_the_same_link_or_change_to_an_available_account(): void
    {
        $original = User::factory()->create();
        $available = User::factory()->create();
        $employee = Employee::create(['name' => 'Trabajador', 'is_active' => true, 'user_id' => $original->id]);
        $url = '/api/employees/'.$employee->id;

        $this->patchJson($url, ['name' => 'Nombre nuevo', 'user_id' => $original->id])
            ->assertOk()->assertJsonPath('data.user_id', $original->id);
        $this->patchJson($url, ['user_id' => $available->id])
            ->assertOk()->assertJsonPath('data.user_id', $available->id);
        $this->assertTrue($employee->refresh()->user->is($available));
        $this->postJson('/api/employees', ['name' => 'Otro trabajador', 'is_active' => true, 'user_id' => $original->id])
            ->assertCreated();
    }

    public function test_update_rejects_an_account_used_by_another_employee_without_partial_changes(): void
    {
        $original = User::factory()->create();
        $occupied = User::factory()->create();
        $employee = Employee::create(['name' => 'Original', 'is_active' => true, 'user_id' => $original->id]);
        Employee::create(['name' => 'Otro', 'is_active' => true, 'user_id' => $occupied->id]);

        $this->patchJson('/api/employees/'.$employee->id, ['name' => 'No guardar', 'user_id' => $occupied->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame($original->id, $employee->refresh()->user_id);
        $this->assertSame('Original', $employee->name);
    }

    public function test_null_links_are_allowed_and_nonexistent_links_are_rejected(): void
    {
        $user = User::factory()->create();
        $employee = Employee::create(['name' => 'Trabajador', 'is_active' => true, 'user_id' => $user->id]);
        $this->patchJson('/api/employees/'.$employee->id, ['user_id' => null])
            ->assertOk()->assertJsonPath('data.user_id', null);
        $this->postJson('/api/employees', ['name' => 'Sin cuenta', 'is_active' => true, 'user_id' => null])
            ->assertCreated();
        $this->patchJson('/api/employees/'.$employee->id, ['user_id' => 999999999])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertNull($employee->refresh()->user_id);
    }

    public function test_postgres_foreign_key_rejects_an_unknown_account(): void
    {
        try {
            DB::transaction(fn () => Employee::create(['name' => 'Inválido', 'is_active' => true, 'user_id' => 999999999]));
            $this->fail('PostgreSQL debe rechazar una cuenta inexistente.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        }
        $this->assertSame(0, Employee::count());
    }

    public function test_postgres_unique_constraint_rejects_a_duplicate_link(): void
    {
        $user = User::factory()->create();
        Employee::create(['name' => 'Original', 'is_active' => true, 'user_id' => $user->id]);
        try {
            DB::transaction(fn () => Employee::create(['name' => 'Duplicado', 'is_active' => true, 'user_id' => $user->id]));
            $this->fail('PostgreSQL debe rechazar el vínculo duplicado.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        $this->assertSame(1, Employee::count());
    }
}
