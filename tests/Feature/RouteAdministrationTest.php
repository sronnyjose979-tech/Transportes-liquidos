<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Models\OperationSite;
use App\Modules\TransportAndDispatch\Models\Route;
use App\Modules\TransportAndDispatch\Models\TransportRequest;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class RouteAdministrationTest extends PostgresTestCase
{
    use TransportFixtures;

    private function attributes(): array
    {
        return ['name' => 'Ruta sintética',
            'origin_site_id' => OperationSite::create(['name' => 'Carga', 'address' => 'Sintético'])->id,
            'destination_site_id' => OperationSite::create(['name' => 'Descarga', 'address' => 'Sintético'])->id];
    }

    public function test_administrator_can_create_read_search_paginate_and_edit_unused_routes(): void
    {
        $this->actingAs($this->actor());
        $original = $this->attributes();
        $created = $this->postJson('/api/catalogs/routes', $original)->assertCreated()
            ->assertJsonPath('data.has_requests', false)->json('data');
        $path = '/api/catalogs/routes/'.$created['id'];
        $this->getJson($path)->assertOk()->assertJsonPath('data.name', $original['name']);
        $this->postJson('/api/catalogs/routes', array_replace($original, ['name' => 'Otra ruta']))->assertCreated();
        $this->getJson('/api/catalogs/routes?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/catalogs/routes?search=sintética')->assertOk()->assertJsonCount(1, 'data');
        $changed = array_replace($original, ['name' => 'Ruta revisada', 'destination_site_id' => $original['origin_site_id']]);
        $this->patchJson($path, $changed + ['expected' => $original])->assertOk()
            ->assertJsonPath('data.name', 'Ruta revisada')->assertJsonPath('data.destination_site_id', $original['origin_site_id']);
        $this->assertDatabaseHas('routes', $changed + ['id' => $created['id']]);
        $this->patchJson($path, $original + ['expected' => $original])->assertConflict();
        $this->assertDatabaseHas('routes', $changed + ['id' => $created['id']]);
        $this->deleteJson($path)->assertMethodNotAllowed();
        $numeric = array_replace($original, ['name' => '01']);
        $numericId = $this->postJson('/api/catalogs/routes', $numeric)->assertCreated()->json('data.id');
        $this->patchJson('/api/catalogs/routes/'.$numericId, $original + ['expected' => array_replace($numeric, ['name' => '1'])])->assertConflict();
    }

    public function test_secretary_can_use_new_route_but_referenced_route_cannot_reinterpret_request_or_trip(): void
    {
        $seed = $this->request();
        $admin = $seed->registeredBy;
        $this->actingAs($admin);
        $attributes = $this->attributes();
        $routeId = $this->postJson('/api/catalogs/routes', $attributes)->assertCreated()->json('data.id');
        $secretary = $this->actor('secretary');
        $this->actingAs($secretary);
        $lookup = $this->getJson('/api/transport/lookups/routes')->assertOk()->json('data');
        $this->assertContains($routeId, array_column($lookup, 'id'));
        $requestId = $this->postJson('/api/transport/requests', [
            'customer_id' => $seed->customer_id, 'liquid_product_id' => $seed->liquid_product_id,
            'route_id' => $routeId, 'requested_liters' => '1000.25', 'requested_at' => '2026-10-12T08:00:00-06:00',
        ])->assertCreated()->assertJsonPath('data.registered_by_id', $secretary->id)->json('data.id');
        $tripId = $this->postJson('/api/transport/trips', ['transport_request_id' => $requestId])->assertCreated()->json('data.id');
        $requestBefore = $this->row(TransportRequest::findOrFail($requestId));
        $tripBefore = $this->row(Trip::findOrFail($tripId));
        $this->actingAs($admin);
        $this->getJson('/api/catalogs/routes/'.$routeId)->assertOk()->assertJsonPath('data.has_requests', true);
        $this->patchJson('/api/catalogs/routes/'.$routeId,
            array_replace($attributes, ['name' => 'Cambio histórico', 'destination_site_id' => $attributes['origin_site_id']])
                + ['expected' => $attributes])->assertConflict();
        $this->assertSame($requestBefore, $this->row(TransportRequest::findOrFail($requestId)));
        $this->assertSame($tripBefore, $this->row(Trip::findOrFail($tripId)));
        $this->assertDatabaseHas('routes', $attributes + ['id' => $routeId]);
        $this->assertSame($attributes['origin_site_id'], TransportRequest::findOrFail($requestId)->route->origin_site_id);
        $this->assertSame($attributes['destination_site_id'], TransportRequest::findOrFail($requestId)->route->destination_site_id);
        $this->rejected(fn () => DB::table('routes')->where('id', $routeId)->delete(), '23514');
        $this->rejected(fn () => DB::table('routes')->where('id', $routeId)->update(['name' => 'SQL histórico']), '23514');
    }

    public function test_administration_requires_active_administrator_and_rechecks_revoked_access(): void
    {
        $attributes = $this->attributes();
        $route = Route::create($attributes);
        $this->getJson('/api/catalogs/routes')->assertUnauthorized();
        $this->postJson('/api/catalogs/routes', $attributes)->assertUnauthorized();
        foreach (['secretary', 'mechanic', 'driver'] as $role) {
            $this->actingAs($this->actor($role));
            $this->getJson('/api/catalogs/routes')->assertForbidden();
            $this->getJson('/api/catalogs/routes/'.$route->id)->assertForbidden();
            $this->postJson('/api/catalogs/routes', $attributes)->assertForbidden();
            $this->patchJson('/api/catalogs/routes/'.$route->id, $attributes + ['expected' => $attributes])->assertForbidden();
        }
        $administrator = $this->actor();
        $this->actingAs($administrator)->getJson('/api/catalogs/routes')->assertOk();
        $administrator->roles()->detach();
        $this->getJson('/api/catalogs/routes')->assertForbidden();
        $this->actingAs($this->actor('administrator', false))->getJson('/api/catalogs/routes')->assertForbidden();
    }

    public function test_references_unknown_fields_and_missing_snapshot_are_validated_without_mutation(): void
    {
        $this->actingAs($this->actor());
        $attributes = $this->attributes();
        foreach (['name' => '', 'origin_site_id' => 'not-a-uuid', 'destination_site_id' => (string) Str::uuid()] as $field => $value) {
            $this->postJson('/api/catalogs/routes', array_replace($attributes, [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/catalogs/routes', $attributes + ['actor_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('actor_id');
        $this->assertSame(0, Route::count());
        $route = Route::create($attributes);
        $this->patchJson('/api/catalogs/routes/'.$route->id, $attributes)->assertUnprocessable()->assertJsonValidationErrors('expected');
        $this->patchJson('/api/catalogs/routes/'.$route->id, $attributes + ['expected' => $attributes, 'actor_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('actor_id');
        $this->getJson('/api/catalogs/routes/'.Str::uuid())->assertNotFound();
        $this->patchJson('/api/catalogs/routes/'.Str::uuid(), $attributes + ['expected' => $attributes])->assertNotFound();
        $this->assertDatabaseHas('routes', $attributes + ['id' => $route->id]);
    }
}
