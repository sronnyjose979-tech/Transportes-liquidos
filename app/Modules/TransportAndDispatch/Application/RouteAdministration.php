<?php

namespace App\Modules\TransportAndDispatch\Application;

use App\Models\User;
use App\Modules\TransportAndDispatch\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RouteAdministration
{
    public function save(User $actor, array $data, ?string $id = null): Route
    {
        try {
            return DB::transaction(function () use ($actor, $data, $id): Route {
                // Same coordinator used by transport request creation and account revocation.
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                Gate::forUser(User::query()->findOrFail($actor->id))->authorize('manage-catalogs');
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $route = $id === null ? new Route : Route::query()->lockForUpdate()->findOrFail($id);
                $before = $id === null ? null : $route->only(['name', 'origin_site_id', 'destination_site_id']);
                if ($id !== null) {
                    // Modification of referenced routes is excluded until historical policy D09 is approved.
                    if ($route->transportRequests()->exists()) {
                        throw new ConflictHttpException('La ruta está utilizada por solicitudes. Su modificación histórica requiere definir D09; registra otra ruta para el nuevo recorrido.');
                    }
                    foreach (['name', 'origin_site_id', 'destination_site_id'] as $field) {
                        if ($before[$field] !== $data['expected'][$field]) {
                            throw new ConflictHttpException('La ruta cambió desde su consulta. Actualiza y revisa los datos antes de editar.');
                        }
                    }
                }
                $route->fill(array_intersect_key($data, array_flip(['name', 'origin_site_id', 'destination_site_id'])))->save();
                $after = $route->only(['name', 'origin_site_id', 'destination_site_id']);
                $context = ['actor_id' => $actor->id, 'route_id' => $route->id, 'before' => $before, 'after' => $after];
                DB::afterCommit(fn () => Log::info('administration.route.'.($id === null ? 'created' : 'updated'), $context));

                return $route->refresh()->loadExists('transportRequests');
            });
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['40001', '40P01', '55P03'], true)) {
                throw new ConflictHttpException('La operación coincidió con otro cambio. Consulta el estado antes de reenviar.');
            }
            if (in_array($state, ['23503', '23514', '23505'], true)) {
                throw ValidationException::withMessages(['route' => 'La ruta incumple las referencias o restricciones existentes.']);
            }
            throw $exception;
        }
    }
}
