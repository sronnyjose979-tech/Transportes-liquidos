<?php

namespace Tests\Feature;

use App\Modules\AccessAndPersonnel\Application\ReadAuthorizationContext;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class SystemPerformanceTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    protected function setUp(): void
    {
        parent::setUp();
        // A disposable schema lets HTTP reads run outside the test transaction.
        $this->prepareWorkshopSchema();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        $this->removeWorkshopSchema();
        parent::tearDown();
    }

    public function test_navigation_preserves_exact_role_links_using_two_queries(): void
    {
        $all = ['dashboard', 'transport', 'driver', 'workshop', 'documents', 'fleet', 'personnel', 'catalogs', 'inventory', 'purchasing', 'billing', 'alerts', 'audit', 'reports', 'expenses'];
        foreach ([
            'administrator' => ['/dashboard', $all],
            'secretary' => ['/transporte', ['transport', 'workshop', 'documents', 'inventory', 'purchasing', 'billing', 'alerts', 'expenses']],
            'mechanic' => ['/taller', ['workshop', 'inventory']],
            'driver' => ['/mis-viajes', ['driver']],
        ] as $role => [$home, $keys]) {
            $this->actingAs($this->actor($role));
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->getJson('/api/navigation')->assertOk()->assertJsonPath('data.home', $home);
            DB::disableQueryLog();
            $this->assertSame($keys, array_column($response->json('data.modules'), 'key'));
            $this->assertCount(2, DB::getQueryLog(), $role);
            $this->assertNull(app('request')->attributes->get(ReadAuthorizationContext::class));
        }
    }

    public function test_role_revocation_and_account_switch_are_seen_on_the_next_request(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin)->getJson('/api/navigation')->assertOk()->assertJsonPath('data.home', '/dashboard');
        $admin->roles()->sync([Role::firstOrCreate(['name' => 'driver'])->id]);
        $this->getJson('/api/navigation')->assertOk()->assertJsonPath('data.home', '/mis-viajes');
        $this->getJson('/api/dashboard')->assertForbidden();
        $admin->roles()->detach();
        $this->getJson('/api/navigation')->assertForbidden();
        $this->actingAs($this->actor('mechanic'))->getJson('/api/navigation')->assertOk()->assertJsonPath('data.home', '/taller');
        $this->actingAs($this->actor('driver', false))->getJson('/api/navigation')->assertForbidden();
    }

    public function test_snapshot_is_not_used_for_other_actors_writes_or_transactions(): void
    {
        $actor = $this->actor();
        $other = $this->actor('driver');
        $request = Request::create('/api/navigation');
        $this->app->instance('request', $request);
        $request->attributes->set(ReadAuthorizationContext::class, new ReadAuthorizationContext($actor));
        $this->assertTrue(Gate::forUser($actor)->allows('manage-personnel'));
        $this->assertFalse(Gate::forUser($other)->allows('manage-personnel'));
        $actor->roles()->detach();
        DB::transaction(function () use ($actor): void {
            $this->assertFalse(Gate::forUser($actor)->allows('manage-personnel'));
            $this->assertFalse(Gate::forUser($actor)->allows('manage-transport-core'));
            $this->assertFalse(app(WorkshopAccess::class)->isOffice($actor));
        });
        $this->assertFalse(Gate::forUser($actor)->allows('manage-personnel'));
        // Even a previously populated GET snapshot cannot be used by a write.
        $request->attributes->set(ReadAuthorizationContext::class, new ReadAuthorizationContext($actor));
        $actor->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
        $this->assertTrue(Gate::forUser($actor)->allows('manage-personnel'));
        $actor->roles()->detach();
        $request->setMethod('POST');
        $this->assertFalse(Gate::forUser($actor)->allows('manage-personnel'));
        $this->assertFalse(Gate::forUser($actor)->allows('manage-transport-core'));
        $this->assertFalse(app(WorkshopAccess::class)->isOffice($actor));
    }

    public function test_dashboard_matches_all_counts_in_order_using_three_queries(): void
    {
        config(['performance.enabled' => true]);
        (new AppServiceProvider($this->app))->boot();
        Log::spy();
        $actor = $this->actor();
        $this->trip($this->request($actor));
        $this->actingAs($actor);
        $definitions = [['fleet_assets', 'Vehículos', '/administracion/flota'], ['employees', 'Empleados', '/administracion/personal'], ['transport_requests', 'Solicitudes de transporte', '/transporte'], ['trips', 'Viajes', '/transporte'], ['workshop_work_orders', 'Órdenes de taller', '/taller'], ['documents', 'Documentos registrados', '/documentacion'], ['purchase_requests', 'Solicitudes de compra', '/compras']];
        $expected = array_map(fn ($entry) => ['key' => $entry[0], 'label' => $entry[1], 'href' => $entry[2], 'value' => DB::table($entry[0])->count()], $definitions);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/dashboard')->assertOk()->assertExactJson(['data' => ['counters' => $expected]]);
        DB::disableQueryLog();
        $this->assertCount(3, DB::getQueryLog());
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => $message === 'performance.request'
            && $context['sql_completed_count'] === 3 && $context['status'] === 200 && $context['sql_completed_ms'] >= 0);
    }
}
