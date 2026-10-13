<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkshopWebTest extends TestCase
{
    public function test_entry_pages_mount_the_existing_frontend_without_private_data(): void
    {
        foreach (['/', '/taller'] as $path) {
            $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('id="workshop-app"', false)->assertSee('lang="es"', false);
        }
    }
}
