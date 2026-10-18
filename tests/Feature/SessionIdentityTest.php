<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use Tests\PostgresTestCase;

class SessionIdentityTest extends PostgresTestCase
{
    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    public function test_stale_identity_cannot_read_or_mutate_even_with_the_same_permissions(): void
    {
        $previous = $this->actor('administrator');
        $current = $this->actor('administrator');
        $this->actingAs($current)->withHeader('X-Session-User', (string) $previous->id);
        $this->getJson('/api/users')->assertStatus(409)->assertJsonPath('code', 'session_changed')
            ->assertHeader('X-Session-User', (string) $current->id)->assertJsonMissingPath('data');
        $this->patchJson('/api/users/'.$previous->id, ['name' => 'No debe guardarse'])->assertStatus(409);
        $this->assertNotSame('No debe guardarse', $previous->fresh()->name);
        $this->postJson('/api/logout')->assertStatus(409);
        $this->assertAuthenticatedAs($current);
    }

    public function test_matching_identity_keeps_existing_contracts_and_permissions(): void
    {
        $user = $this->actor('secretary');
        $this->actingAs($user)->withHeader('X-Session-User', (string) $user->id);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('data.id', (string) $user->id)
            ->assertHeader('X-Session-User', (string) $user->id);
        $this->getJson('/api/users')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
        $this->get('/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_header_never_authenticates_a_guest_or_grants_permissions(): void
    {
        $user = $this->actor('administrator');
        $this->withHeader('X-Session-User', (string) $user->id)->getJson('/api/users')->assertUnauthorized();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_clients_without_header_keep_cookie_authentication_and_authorization(): void
    {
        $user = $this->actor('driver');
        $this->actingAs($user)->getJson('/api/session')->assertOk()->assertHeader('X-Session-User', (string) $user->id);
        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest();
    }
}
