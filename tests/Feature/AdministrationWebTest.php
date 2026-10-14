<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use Tests\PostgresTestCase;

class AdministrationWebTest extends PostgresTestCase
{
    public function test_administration_shells_expose_no_private_data_and_only_supported_sections(): void
    {
        $this->withoutVite();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
        $this->actingAs($actor);
        foreach (['flota', 'personal', 'catalogos', 'compras'] as $section) {
            $response = $this->get('/administracion/'.$section)->assertOk()->assertViewIs('administration')
                ->assertSee('administration-app')->assertSee('data-section="'.$section.'"', false)
                ->assertDontSee('password')->assertDontSee('storage_key')->assertDontSee('employee_id');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->get('/administracion/otra')->assertNotFound();
    }
}
