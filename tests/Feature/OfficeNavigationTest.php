<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Models\Role;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class OfficeNavigationTest extends PostgresTestCase
{
    use TransportFixtures;

    private function context(string $path)
    {
        return $this->withHeader('X-Office-Navigation', '1')->getJson($path);
    }

    public function test_direct_access_and_transitions_use_the_same_destination_permissions(): void
    {
        $this->withoutVite();
        foreach (['administrator', 'secretary', 'mechanic', 'driver'] as $role) {
            $actor = $this->actor($role);
            $this->actingAs($actor);
            foreach (['/dashboard' => 'dashboard', '/transporte' => 'transport', '/administracion/flota' => 'fleet'] as $path => $page) {
                $allowed = $role === 'administrator' || ($role === 'secretary' && $page === 'transport');
                $this->flushHeaders();
                if ($allowed) {
                    $this->get($path)->assertOk()->assertSee('id="office-app"', false)->assertHeader('Cache-Control', 'no-store, private');
                    $this->context($path)->assertOk()->assertJsonPath('data.page', $page)
                        ->assertJsonPath('data.session.id', (string) $actor->id)->assertJsonPath('data.session.roles.0', $role)
                        ->assertJsonStructure(['data' => ['navigation' => ['home', 'modules']]])
                        ->assertDontSee('password')->assertDontSee('remember_token');
                } else {
                    $this->get($path)->assertForbidden();
                    $this->context($path)->assertForbidden();
                }
            }
        }
    }

    public function test_each_transition_rechecks_roles_and_active_account(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $this->context('/administracion/flota')->assertOk();
        $actor->roles()->sync([Role::firstOrCreate(['name' => 'secretary'])->id]);
        $this->context('/administracion/flota')->assertForbidden();
        $this->context('/transporte')->assertOk();
        $actor->forceFill(['is_active' => false])->save();
        $this->context('/transporte')->assertForbidden();
    }

    public function test_expired_session_and_cross_account_identity_cannot_obtain_a_context(): void
    {
        $this->context('/dashboard')->assertUnauthorized();
        $first = $this->actor();
        $second = $this->actor();
        $this->actingAs($second)->withHeader('X-Session-User', (string) $first->id);
        $this->context('/transporte')->assertStatus(409)->assertJsonPath('code', 'session_changed')->assertJsonMissingPath('data');
        $this->withHeader('X-Session-User', (string) $second->id);
        $this->context('/transporte')->assertOk()->assertHeader('X-Session-User', (string) $second->id);
    }

    public function test_driver_and_other_modules_keep_their_document_roots(): void
    {
        $this->withoutVite();
        $this->actingAs($this->actor());
        $this->get('/mis-viajes')->assertOk()->assertSee('id="system-app"', false)->assertDontSee('id="office-app"', false);
        $this->get('/administracion/personal')->assertOk()->assertSee('id="administration-app"', false);
        $this->get('/taller')->assertOk()->assertSee('id="workshop-app"', false);
    }

    public function test_initial_context_cannot_inject_html(): void
    {
        $this->withoutVite();
        $actor = $this->actor();
        $actor->forceFill(['login' => '</script><script>alert(1)</script>'])->save();
        $this->actingAs($actor)->get('/dashboard')->assertOk()->assertDontSee('</script><script>alert(1)</script>', false);
    }
}
