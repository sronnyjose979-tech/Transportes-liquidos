<?php

namespace Tests\Feature;

use Tests\PostgresTestCase;

class AdministrationWebTest extends PostgresTestCase
{
    public function test_administration_shells_expose_no_private_data_and_only_supported_sections(): void
    {
        $this->withoutVite();
        foreach (['flota', 'personal', 'catalogos', 'compras'] as $section) {
            $response = $this->get('/administracion/'.$section)->assertOk()->assertViewIs('administration')
                ->assertSee('administration-app')->assertSee('data-section="'.$section.'"', false)
                ->assertDontSee('password')->assertDontSee('storage_key')->assertDontSee('employee_id');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->get('/administracion/otra')->assertNotFound();
    }
}
