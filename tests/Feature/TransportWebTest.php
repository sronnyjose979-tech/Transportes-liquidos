<?php

namespace Tests\Feature;

use Tests\PostgresTestCase;

class TransportWebTest extends PostgresTestCase
{
    public function test_transport_shell_contains_no_private_data_and_uses_vue_vite_and_no_store(): void
    {
        $this->withoutVite();
        $response = $this->get('/transporte')->assertOk()->assertViewIs('transport')->assertSee('transport-app')->assertSee('lang="es"', false)->assertSee('viewport');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee('storage_key')->assertDontSee('requested_liters')->assertDontSee('password');
    }
}
