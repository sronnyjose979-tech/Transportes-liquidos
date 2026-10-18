<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

class GuidedPersonnelTest extends PostgresTestCase
{
    private function administrator(): User
    {
        $actor = User::factory()->create(['login' => 'admin-guided', 'is_active' => true]);
        $actor->roles()->attach(Role::create(['name' => 'administrator']));

        return $actor;
    }

    private function payload(): array
    {
        $role = Role::create(['name' => 'driver']);

        return ['name' => 'Persona sintética', 'login' => 'conductor-sintetico', 'email' => 'driver@example.test',
            'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!',
            'roles' => [$role->id], 'is_active' => true,
            'employee' => ['name' => 'Empleado sintético', 'is_active' => true]];
    }

    public function test_guided_registration_creates_linked_person_with_no_partial_or_duplicate_account(): void
    {
        $this->actingAs($this->administrator());
        $data = $this->payload();
        $response = $this->postJson('/api/users', $data)->assertCreated()->assertJsonMissingPath('data.password');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('employees', ['user_id' => $id, 'name' => 'Empleado sintético', 'is_active' => true]);
        $this->assertNotNull($response->json('data.employee_id'));
        $this->assertSame('driver', User::findOrFail($id)->roles->sole()->name);
        $this->postJson('/api/users', $data)->assertUnprocessable();
        $this->assertSame(1, User::where('login', $data['login'])->count());
        $this->assertSame(1, Employee::where('user_id', $id)->count());
    }

    public function test_invalid_or_protected_employee_fields_create_nothing_and_update_cannot_create_employee(): void
    {
        $actor = $this->administrator();
        $this->actingAs($actor);
        $data = $this->payload();
        foreach ([[], null, 'Persona', ['name' => '', 'is_active' => true], ['name' => 'Persona'], ['name' => 'Persona', 'is_active' => true, 'user_id' => $actor->id]] as $employee) {
            $this->postJson('/api/users', [...$data, 'employee' => $employee])->assertUnprocessable();
        }
        $this->patchJson('/api/users/'.$actor->id, ['employee' => $data['employee']])->assertUnprocessable();
        $this->assertSame(1, User::count());
        $this->assertSame(0, Employee::count());
    }

    public function test_failure_in_employee_persistence_rolls_back_account_and_roles(): void
    {
        $this->actingAs($this->administrator());
        $data = $this->payload();
        DB::unprepared("CREATE FUNCTION pg_temp.reject_guided_employee() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic failure' USING ERRCODE='23514'; END $$;
            CREATE TRIGGER reject_guided_employee BEFORE INSERT ON employees FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_guided_employee()");
        $this->postJson('/api/users', $data)->assertStatus(500);
        $this->assertSame(1, User::count());
        $this->assertSame(0, Employee::count());
        $this->assertSame(1, DB::table('user_roles')->count());
    }

    public function test_anonymous_and_non_administrators_cannot_use_guided_creation(): void
    {
        $data = $this->payload();
        $this->postJson('/api/users', $data)->assertUnauthorized();
        foreach (['secretary', 'mechanic', 'driver'] as $name) {
            $actor = User::factory()->create(['is_active' => true]);
            $role = Role::firstOrCreate(['name' => $name]);
            $actor->roles()->attach($role);
            $this->actingAs($actor)->postJson('/api/users', $data)->assertForbidden();
        }
        $this->assertSame(0, Employee::count());
    }
}
