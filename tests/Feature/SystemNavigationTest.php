<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class SystemNavigationTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_guests_are_redirected_and_private_apis_reject_them(): void
    {
        $this->withoutVite();
        foreach (['/', '/dashboard', '/transporte', '/taller', '/inventario', '/compras', '/documentacion', '/mis-viajes', '/facturacion', '/administracion/flota'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/login')->assertOk()->assertSee('system-app');
        foreach (['navigation', 'dashboard', 'transport/my-trips', 'billing/trips'] as $url) {
            $this->getJson('/api/'.$url)->assertUnauthorized();
        }
    }

    public function test_role_homes_and_navigation_match_server_permissions(): void
    {
        $this->withoutVite();
        foreach (['administrator' => '/dashboard', 'secretary' => '/transporte', 'mechanic' => '/taller', 'driver' => '/mis-viajes'] as $role => $home) {
            $this->actingAs($this->actor($role));
            $this->get('/')->assertRedirect($home);
            $navigation = $this->getJson('/api/navigation')->assertOk()->assertJsonPath('data.home', $home)->json('data.modules');
            foreach ($navigation as $link) {
                $this->get($link['href'])->assertOk();
            }
            $this->assertContains($home, array_column($navigation, 'href'));
            if ($role !== 'administrator') {
                $this->get('/dashboard')->assertForbidden();
                $this->get('/administracion/personal')->assertForbidden();
                $this->getJson('/api/dashboard')->assertForbidden();
            }
            if (in_array($role, ['mechanic', 'driver'])) {
                $this->get('/facturacion')->assertForbidden();
                $this->get('/transporte')->assertForbidden();
                $this->getJson('/api/billing/trips')->assertForbidden();
            }
        }
    }

    public function test_revoked_and_inactive_accounts_cannot_keep_page_or_api_access(): void
    {
        $user = $this->actor();
        $this->actingAs($user);
        $user->roles()->detach();
        $this->get('/dashboard')->assertForbidden();
        $this->getJson('/api/dashboard')->assertForbidden();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
        User::whereKey($user->id)->update(['is_active' => false]);
        $this->get('/dashboard')->assertForbidden();
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_counts_real_data_without_financial_estimates(): void
    {
        $user = $this->actor();
        $this->trip($this->request($user));
        $this->actingAs($user);
        $counters = $this->getJson('/api/dashboard')->assertOk()->json('data.counters');
        $this->assertSame(1, collect($counters)->firstWhere('key', 'trips')['value']);
        $this->assertSame(1, collect($counters)->firstWhere('key', 'transport_requests')['value']);
        $this->assertNotContains('revenue', array_column($counters, 'key'));
    }

    public function test_driver_queries_only_current_own_trips_and_loses_access_after_release(): void
    {
        $trip = $this->trip($this->request());
        $this->ensureAssignments($trip);
        $assignment = $trip->driverAssignments()->current()->firstOrFail();
        $driver = $assignment->employee->user;
        $other = $this->trip($this->request());
        $this->ensureAssignments($other);
        $this->actingAs($driver);
        $this->getJson('/api/transport/my-trips')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $trip->id)->assertDontSee('email')->assertDontSee('password')->assertDontSee('storage_key');
        $this->getJson('/api/transport/my-trips/'.$other->id)->assertNotFound();
        $this->getJson('/api/transport/my-trips/'.$trip->id)->assertOk();
        app(AssignmentAdministration::class)->release($trip->registeredBy, $trip->id, 'driver', 'Liberación manual');
        $this->getJson('/api/transport/my-trips')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/transport/my-trips/'.$trip->id)->assertNotFound();
    }

    public function test_billing_review_marks_unapproved_trips_ineligible(): void
    {
        $user = $this->actor('secretary');
        $trip = $this->trip($this->request());
        $this->actingAs($user);
        $this->getJson('/api/billing/trips?customer_id='.$trip->transportRequest->customer_id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.billing_eligibility', false)->assertJsonPath('billing_enabled', true);
        $this->getJson('/api/billing/trips?execution_status=completed')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/billing/trips?per_page=1000')->assertUnprocessable();
        $this->postJson('/api/billing/trips', [])->assertStatus(405);
    }
}
