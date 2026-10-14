<?php

namespace App\Modules\AccessAndPersonnel\Application;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class SystemNavigation
{
    public function links(User $actor): array
    {
        $actor = $actor->fresh();
        abort_unless($actor?->is_active, 403);
        $definitions = [
            ['dashboard', 'Inicio', '/dashboard', 'manage-personnel'],
            ['transport', 'Transporte', '/transporte', 'manage-transport-core'],
            ['driver', 'Mis viajes', '/mis-viajes', 'view-assigned-trips'],
            ['workshop', 'Taller', '/taller', 'access-workshop'],
            ['documents', 'Documentación', '/documentacion', 'manage-fleet-documents'],
            ['fleet', 'Flota', '/administracion/flota', 'manage-fleet'],
            ['personnel', 'Personal', '/administracion/personal', 'manage-personnel'],
            ['catalogs', 'Catálogos', '/administracion/catalogos', 'manage-catalogs'],
            ['inventory', 'Inventario', '/inventario', 'access-inventory'],
            ['purchasing', 'Compras', '/compras', 'access-purchasing'],
            ['billing', 'Facturación', '/facturacion', 'manage-billing'],
            ['expenses', 'Gastos', '/gastos', 'manage-expenses'],
        ];
        $links = [];
        foreach ($definitions as [$key, $label, $href, $ability]) {
            try {
                $allowed = Gate::forUser($actor)->allows($ability);
            } catch (AuthorizationException) {
                $allowed = false;
            }
            if ($allowed) {
                $links[] = compact('key', 'label', 'href');
            }
        }

        return $links;
    }

    public function home(User $actor): string
    {
        $links = array_column($this->links($actor), 'href', 'key');
        foreach (['dashboard', 'transport', 'workshop', 'driver'] as $key) {
            if (isset($links[$key])) {
                return $links[$key];
            }
        }
        abort(403, 'No tiene un módulo de inicio autorizado.');
    }
}
