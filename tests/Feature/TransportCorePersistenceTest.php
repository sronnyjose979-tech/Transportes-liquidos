<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Models\LiquidProduct;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportCorePersistenceTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_existing_catalogs_relations_and_exact_numeric_volume(): void
    {
        $request = $this->request($this->actor('secretary'));
        $trip = $this->trip($request);
        $this->assertTrue(Str::isUuid($request->id));
        $this->assertTrue($trip->transportRequest->is($request));
        $this->assertTrue($trip->liquidProduct->is($request->liquidProduct));
        $this->assertSame('1000.25', $request->requested_liters);
        $this->assertSame('scheduled', $trip->planning_status);
        $this->assertSame('pending', $trip->execution_status);
        $this->assertSame('open', $trip->administrative_status);
        $this->assertTrue($request->route->originSite->exists);
        $this->assertTrue($request->route->destinationSite->exists);
        $this->assertTrue($request->registeredBy->is($trip->registeredBy));
        $this->trip($request);
        $this->assertSame(2, $request->trips()->count());
        $column = DB::selectOne("SELECT numeric_precision,numeric_scale FROM information_schema.columns WHERE table_name='transport_requests' AND column_name='requested_liters'");
        $this->assertSame(15, $column->numeric_precision);
        $this->assertSame(2, $column->numeric_scale);
        $this->change($request, ['requested_liters' => '9999999999999.99']);
        $this->checkConstraints();
        $this->assertSame('9999999999999.99', $request->fresh()->requested_liters);
    }

    public function test_foreign_keys_volume_and_independent_state_constraints(): void
    {
        $request = $this->request();
        $trip = $this->trip($request);
        $this->rejected(fn () => DB::table('transport_requests')->where('id', $request->id)->update(['requested_liters' => 0]));
        $this->rejected(fn () => DB::table('transport_requests')->where('id', $request->id)->update(['requested_liters' => 'NaN']));
        $this->rejected(fn () => $this->change($request, ['customer_id' => (string) Str::uuid()]), '23503');
        foreach (['planning_status' => 'draft', 'execution_status' => 'unknown', 'administrative_status' => 'under_review', 'scheduled_start_at' => '2026-10-15T12:00:00Z'] as $key => $value) {
            $fields = [$key => $value];
            if ($key === 'scheduled_start_at') {
                $fields['scheduled_end_at'] = '2026-10-14T12:00:00Z';
            }
            $this->rejected(fn () => $this->change($trip, $fields));
            $trip->refresh();
        }
        $this->rejected(fn () => $this->change($trip, ['execution_status' => 'completed']));
        $this->rejected(fn () => $this->change($trip, ['administrative_status' => 'closed']));
    }

    public function test_historical_product_is_preserved_after_authorized_request_change_and_catalog_edit(): void
    {
        $request = $this->request();
        $trip = $this->trip($request);
        $this->productChange($request);
        $this->checkConstraints();
        $this->assertNotSame($trip->liquid_product_id, $request->fresh()->liquid_product_id);
        $trip->liquidProduct->update(['name' => 'Nombre nuevo']);
        $this->assertSame('Agua', $trip->fresh()->product_name_snapshot);
        $this->assertSame(1, $trip->productReviews()->count());
        $this->rejected(fn () => $this->change($trip, ['liquid_product_id' => $request->liquid_product_id]));
    }

    public function test_initial_product_matches_request_and_referenced_route_cannot_change(): void
    {
        $request = $this->request();
        $other = LiquidProduct::create(['name' => 'Otro', 'is_active' => true]);
        $this->rejected(fn () => Trip::create(['transport_request_id' => $request->id, 'liquid_product_id' => $other->id,
            'product_name_snapshot' => 'Otro', 'registered_by_id' => $request->registered_by_id]));
        $this->rejected(fn () => $request->route->update(['name' => 'Cambio retrospectivo']));
    }

    public function test_cancellation_retains_trip_and_requires_actor_and_reason(): void
    {
        $trip = $this->trip($this->request());
        $this->rejected(fn () => $this->change($trip, ['execution_status' => 'cancelled']));
        $trip->refresh();
        $this->change($trip, ['execution_status' => 'cancelled', 'cancelled_at' => '2026-10-12T12:00:00Z',
            'cancelled_by_id' => $trip->registered_by_id, 'cancellation_reason' => 'Cliente cancela']);
        $this->checkConstraints();
        $this->assertSame('cancelled', $trip->fresh()->execution_status);
        $this->assertSame('open', $trip->fresh()->administrative_status);
        $this->rejected(fn () => DB::table('trips')->where('id', $trip->id)->delete());
    }

    public function test_model_rejects_more_than_two_decimals_instead_of_rounding(): void
    {
        $request = $this->request();
        $this->expectException(\InvalidArgumentException::class);
        $request->update(['requested_liters' => '1000.251']);
    }
}
