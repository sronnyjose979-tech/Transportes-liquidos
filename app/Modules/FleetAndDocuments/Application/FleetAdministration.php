<?php

namespace App\Modules\FleetAndDocuments\Application;

use App\Models\User;
use App\Modules\FleetAndDocuments\Models\Compartment;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use App\Modules\FleetAndDocuments\Models\Tanker;
use App\Modules\FleetAndDocuments\Models\TractorUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FleetAdministration
{
    public function save(User $actor, string $type, array $data, ?string $id = null): FleetAsset
    {
        try {
            return DB::transaction(function () use ($actor, $type, $data, $id): FleetAsset {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                Gate::forUser(User::query()->findOrFail($actor->id))->authorize('manage-fleet');
                if ($id === null) {
                    $asset = FleetAsset::create(['code' => $data['code'], 'type' => $type, 'service_status' => 'available']);
                    if ($type === 'tractor_unit') {
                        TractorUnit::create(['fleet_asset_id' => $asset->id]);
                    } else {
                        Tanker::create(['fleet_asset_id' => $asset->id, 'has_physical_partitions' => $data['has_physical_partitions']]);
                        foreach ($data['compartments'] as $compartment) {
                            Compartment::create([...$compartment, 'tanker_id' => $asset->id]);
                        }
                    }
                } else {
                    $asset = FleetAsset::query()->where('type', $type)->lockForUpdate()->findOrFail($id);
                    if (array_key_exists('code', $data)) {
                        $asset->code = $data['code'];
                        $asset->save();
                    }
                    foreach ($data['compartments'] ?? [] as $index => $values) {
                        $compartment = Compartment::query()->where('tanker_id', $asset->id)->lockForUpdate()->find($values['id']);
                        if ($compartment === null) {
                            throw ValidationException::withMessages(['compartments.'.$index.'.id' => 'El compartimento no pertenece a esta cisterna.']);
                        }
                        $compartment->fill(array_intersect_key($values, array_flip(['code', 'capacity_liters'])))->save();
                    }
                }
                // Check the entire aggregate before returning a successful HTTP response.
                DB::statement('SET CONSTRAINTS fleet_asset_integrity, tractor_unit_integrity, tanker_integrity, compartment_integrity IMMEDIATE');
                DB::statement('SET CONSTRAINTS fleet_asset_integrity, tractor_unit_integrity, tanker_integrity, compartment_integrity DEFERRED');

                return $asset->load('tanker.compartments');
            });
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if ($state === '23505') {
                $field = str_contains($exception->getMessage(), 'compartments') ? 'compartments' : 'code';
                throw ValidationException::withMessages([$field => 'El código ya está en uso dentro de su ámbito.']);
            }
            if (in_array($state, ['23503', '23514'], true)) {
                throw ValidationException::withMessages(['compartments' => 'La configuración de la unidad no cumple las restricciones de integridad de flota.']);
            }
            throw $exception;
        }
    }
}
