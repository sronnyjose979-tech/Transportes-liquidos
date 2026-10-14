<?php

namespace App\Modules\AccessAndPersonnel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessAndPersonnel\Application\SystemNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SystemController extends Controller
{
    public function navigation(Request $request, SystemNavigation $navigation): JsonResponse
    {
        return response()->json(['data' => ['home' => $navigation->home($request->user()), 'modules' => $navigation->links($request->user())]])->header('Cache-Control', 'private, no-store');
    }

    public function dashboard(): JsonResponse
    {
        Gate::authorize('manage-personnel');
        $counters = [];
        foreach ([['fleet_assets', 'Vehículos', '/administracion/flota'], ['employees', 'Empleados', '/administracion/personal'], ['transport_requests', 'Solicitudes de transporte', '/transporte'], ['trips', 'Viajes', '/transporte'], ['workshop_work_orders', 'Órdenes de taller', '/taller'], ['documents', 'Documentos registrados', '/documentacion'], ['purchase_requests', 'Solicitudes de compra', '/compras']] as [$table, $label, $href]) {
            $counters[] = ['key' => $table, 'label' => $label, 'href' => $href, 'value' => DB::table($table)->count()];
        }

        return response()->json(['data' => compact('counters')])->header('Cache-Control', 'private, no-store');
    }
}
