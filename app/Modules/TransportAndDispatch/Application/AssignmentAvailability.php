<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\TransportAndDispatch\Models\DriverAssignment;
use App\Modules\TransportAndDispatch\Models\TransportAssignmentConflict;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripAssetAssignment;
use Illuminate\Validation\ValidationException;

class AssignmentAvailability
{
    public function inspect(string $kind, string $id): array
    {
        if ($kind === 'driver') {
            $resource = Employee::query()->findOrFail($id);
            $eligible = $resource->is_active && $resource->user !== null && $resource->user->is_active && $resource->user->roles()->where('name', 'driver')->exists();
            $conflicts = DriverAssignment::current()->where('employee_id', $id)->orderBy('id')->get();
        } else {
            $resource = FleetAsset::query()->findOrFail($id);
            $eligible = $resource->type === $kind && $resource->service_status === 'available';
            $conflicts = TripAssetAssignment::current()->where('fleet_asset_id', $id)->orderBy('id')->get();
        }

        return ['resource_kind' => $kind, 'resource_id' => $id, 'eligible' => (bool) $eligible,
            'available' => $eligible && $conflicts->isEmpty(), 'conflicts' => $conflicts];
    }

    public function revalidate(Trip $trip): void
    {
        foreach ($trip->driverAssignments()->current()->get()->concat($trip->assetAssignments()->current()->get()) as $assignment) {
            $driver = $assignment instanceof DriverAssignment;
            $info = $this->inspect($driver ? 'driver' : $assignment->asset_type, $driver ? $assignment->employee_id : $assignment->fleet_asset_id);
            if (! $info['eligible']) {
                throw ValidationException::withMessages(['assignment' => 'Recurso asignado ya no elegible.']);
            }
            foreach ($info['conflicts'] as $other) {
                if ($other->trip_id === $trip->id) {
                    continue;
                }
                $column = $driver ? 'driver_assignment_id' : 'asset_assignment_id';
                // Existing approval covers the same effective occupancy, never new resources or occupants.
                $covered = TransportAssignmentConflict::query()->where(function ($q) use ($column, $assignment, $other) {
                    $q->where(fn ($x) => $x->where('operation_id', $assignment->operation_id)->where($column, $other->id))
                        ->orWhere(fn ($x) => $x->where('operation_id', $other->operation_id)->where($column, $assignment->id));
                })->whereHas('operation.applications.decision.authorizationRequest', fn ($q) => $q->where('type', 'assignment_conflict'))->exists();
                if (! $covered) {
                    throw ValidationException::withMessages(['assignment' => 'Conflicto efectivo sin autorización aplicable.']);
                }
            }
        }
    }
}
