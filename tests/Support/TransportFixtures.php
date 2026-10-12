<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Models\Customer;
use App\Modules\TransportAndDispatch\Models\LiquidProduct;
use App\Modules\TransportAndDispatch\Models\OperationSite;
use App\Modules\TransportAndDispatch\Models\Route;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationApplication;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationDecision;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use App\Modules\TransportAndDispatch\Models\TransportChange;
use App\Modules\TransportAndDispatch\Models\TransportRequest;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripProductReview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait TransportFixtures
{
    protected function actor(string $role = 'administrator', bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    protected function request(?User $actor = null): TransportRequest
    {
        $actor ??= $this->actor();
        $origin = OperationSite::create(['name' => 'Origen', 'address' => 'Sintético']);
        $destination = OperationSite::create(['name' => 'Destino', 'address' => 'Sintético']);
        $route = Route::create(['name' => 'Ruta', 'origin_site_id' => $origin->id, 'destination_site_id' => $destination->id]);
        $product = LiquidProduct::create(['name' => 'Agua', 'is_active' => true]);

        return TransportRequest::create(['customer_id' => Customer::create(['name' => 'Cliente'])->id, 'liquid_product_id' => $product->id,
            'route_id' => $route->id, 'requested_liters' => '1000.25', 'requested_at' => '2026-10-11T12:00:00Z', 'registered_by_id' => $actor->id])->refresh();
    }

    protected function trip(TransportRequest $request): Trip
    {
        return Trip::create(['transport_request_id' => $request->id, 'liquid_product_id' => $request->liquid_product_id,
            'product_name_snapshot' => $request->liquidProduct->name, 'registered_by_id' => $request->registered_by_id])->refresh();
    }

    protected function row(Model $model): array
    {
        return json_decode(DB::selectOne('SELECT to_jsonb(t)::text AS data FROM '.$model->getTable().' t WHERE id=?', [$model->id])->data, true);
    }

    protected function change(Model $target, array $data, ?User $actor = null): TransportChange
    {
        $actor ??= User::findOrFail($target->registered_by_id);
        if ($target instanceof Trip && in_array($data['execution_status'] ?? null, ['in_progress', 'completed'], true)) {
            $this->ensureAssignments($target);
        }
        if ($target instanceof Trip && ($data['execution_status'] ?? null) === 'completed'
            && isset($data['officially_completed_at']) && ! $target->tripLoad()->exists()) {
            $this->operationalRecords($target);
        }
        $before = $this->row($target);
        $target->forceFill($data + ['version' => $before['version'] + 1])->save();
        $after = $this->row($target);

        return TransportChange::create(['transport_request_id' => $target instanceof TransportRequest ? $target->id : null,
            'trip_id' => $target instanceof Trip ? $target->id : null, 'action' => 'correction', 'before_values' => $before,
            'after_values' => $after, 'from_version' => $before['version'], 'to_version' => $after['version'],
            'actor_id' => $actor->id, 'reason' => 'Corrección sintética']);
    }

    protected function operationalRecords(Trip $trip): void
    {
        $service = app(TripOperations::class);
        $assignment = $trip->assetAssignments()->current()->where('asset_type', 'tanker')->firstOrFail();
        $compartment = $assignment->fleetAsset->tanker->compartments->first();
        $load = $service->load($trip->registeredBy, $trip->id, $trip->transportRequest->route->origin_site_id,
            [$compartment->id => '100.00'], CarbonImmutable::now(), 'Fixture de carga');
        $service->unload($trip->registeredBy, $trip->id, $trip->transportRequest->route->destination_site_id,
            [$load->items->first()->id => '100.00'], CarbonImmutable::now(), 'Fixture de descarga');
    }

    protected function intent(TransportRequest $request, LiquidProduct $product): TransportAuthorizationRequest
    {
        return TransportAuthorizationRequest::create(['transport_request_id' => $request->id, 'type' => 'product_change',
            'content' => ['before' => ['liquid_product_id' => $request->liquid_product_id], 'after' => ['liquid_product_id' => $product->id]],
            'target_version' => $request->version, 'requested_by_id' => $request->registered_by_id, 'reason' => 'Producto solicitado']);
    }

    protected function productChange(TransportRequest $request, ?User $admin = null): array
    {
        $admin ??= $this->actor();
        $product = LiquidProduct::create(['name' => 'Melaza', 'is_active' => true]);
        $intent = $this->intent($request, $product);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved',
            'decided_by_id' => $admin->id, 'reason' => 'Autorización sintética']);
        $previous = $request->liquid_product_id;
        $change = $this->change($request, ['liquid_product_id' => $product->id]);
        foreach ($request->trips as $trip) {
            TripProductReview::create(['trip_id' => $trip->id, 'request_change_id' => $change->id, 'previous_product_id' => $previous,
                'proposed_product_id' => $product->id, 'result' => 'retained', 'reviewed_by_id' => $admin->id, 'reason' => 'Conserva producto histórico']);
        }
        $application = TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $request->registered_by_id]);

        return [$intent, $decision, $change, $application];
    }

    protected function closedTrip(): Trip
    {
        $trip = $this->trip($this->request());
        $this->change($trip, ['execution_status' => 'completed', 'administrative_status' => 'closed',
            'officially_started_at' => '2026-10-11T12:00:00Z', 'started_by_id' => $trip->registered_by_id,
            'officially_completed_at' => '2026-10-12T12:00:00Z', 'completed_by_id' => $trip->registered_by_id,
            'closed_at' => '2026-10-13T12:00:00Z', 'closed_by_id' => $trip->registered_by_id]);
        $this->checkConstraints();

        return $trip->refresh();
    }

    protected function checkConstraints(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    protected function rejected(callable $write, string|array $states = '23514'): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('La escritura debía rechazarse.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], (array) $states);
        }
    }

    protected function assignmentResources(): array
    {
        $admin = $this->actor();
        $fleet = app(FleetAdministration::class);
        $tractor = $fleet->save($admin, 'tractor_unit', ['code' => (string) Str::uuid()]);
        $tanker = $fleet->save($admin, 'tanker', ['code' => (string) Str::uuid(), 'has_physical_partitions' => false,
            'compartments' => [['code' => 'A', 'capacity_liters' => '1000', 'is_active' => true]]]);
        $employee = Employee::create(['name' => 'Conductor sintético', 'is_active' => true, 'user_id' => $this->actor('driver')->id]);

        return ['driver' => $employee, 'tractor_unit' => $tractor, 'tanker' => $tanker];
    }

    protected function ensureAssignments(Trip $trip): void
    {
        $service = app(AssignmentAdministration::class);
        $resources = $this->assignmentResources();
        foreach ($resources as $kind => $resource) {
            $query = $kind === 'driver' ? $trip->driverAssignments()->current() : $trip->assetAssignments()->current()->where('asset_type', $kind);
            if (! $query->exists()) {
                $service->assign($trip->registeredBy, $trip->id, $kind, $resource->id, 'Preparación de fixture activo');
            }
        }
    }
}
