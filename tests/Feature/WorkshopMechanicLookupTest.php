<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Employee;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class WorkshopMechanicLookupTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function tearDown(): void
    {
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    public function test_office_can_find_unassigned_eligible_mechanics_without_personal_information(): void
    {
        $this->prepareWorkshopSchema();
        $mechanic = $this->actor('mechanic');
        $eligible = Employee::create(['user_id' => $mechanic->id, 'name' => 'Mecánico elegible', 'is_active' => true]);
        Employee::create(['user_id' => $this->actor('mechanic')->id, 'name' => 'Empleado inactivo', 'is_active' => false]);
        Employee::create(['user_id' => $this->actor('mechanic', false)->id, 'name' => 'Cuenta inactiva', 'is_active' => true]);
        Employee::create(['user_id' => $this->actor('driver')->id, 'name' => 'Conductor', 'is_active' => true]);
        Employee::create(['name' => 'Sin cuenta', 'is_active' => true]);
        foreach (['administrator', 'secretary'] as $role) {
            $response = $this->actingAs($this->actor($role))->getJson('/api/workshop/lookups/mechanics?per_page=1')
                ->assertOk()->assertJsonStructure(['data', 'meta', 'links'])->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $eligible->id)->assertJsonPath('data.0.name', 'Mecánico elegible')
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
            $this->getJson('/api/workshop/lookups/mechanics?search=elegible')->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson('/api/workshop/lookups/mechanics?search=%25')->assertOk()->assertJsonPath('meta.total', 0);
            $this->getJson('/api/workshop/lookups/mechanics?per_page=101')->assertUnprocessable();
            $this->getJson('/api/workshop/lookups/mechanics?actor_id=1')->assertUnprocessable();
        }
        DB::table('user_roles')->where('user_id', $mechanic->id)->delete();
        $this->getJson('/api/workshop/lookups/mechanics')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_lookup_requires_active_office_access_and_rechecks_revocation(): void
    {
        $this->prepareWorkshopSchema();
        $this->getJson('/api/workshop/lookups/mechanics')->assertUnauthorized();
        foreach (['mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/workshop/lookups/mechanics')->assertForbidden();
        }
        $this->actingAs($this->actor('secretary', false))->getJson('/api/workshop/lookups/mechanics')->assertForbidden();
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary)->getJson('/api/workshop/lookups/mechanics')->assertOk();
        DB::table('user_roles')->where('user_id', $secretary->id)->delete();
        $this->getJson('/api/workshop/lookups/mechanics')->assertForbidden();
    }
}
