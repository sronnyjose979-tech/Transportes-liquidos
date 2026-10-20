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
        $links = $navigation->links($request->user());

        return response()->json(['data' => ['home' => $navigation->homeFromLinks($links), 'modules' => $links]])->header('Cache-Control', 'private, no-store');
    }

    public function dashboard(): JsonResponse
    {
        Gate::authorize('manage-personnel');
        $definitions = [['fleet_assets', 'Vehículos', '/administracion/flota'], ['employees', 'Empleados', '/administracion/personal'], ['transport_requests', 'Solicitudes de transporte', '/transporte'], ['trips', 'Viajes', '/transporte'], ['workshop_work_orders', 'Órdenes de taller', '/taller'], ['documents', 'Documentos registrados', '/documentacion'], ['purchase_requests', 'Solicitudes de compra', '/compras']];
        $query = DB::query();
        foreach ($definitions as [$table]) {
            $query->selectSub(DB::table($table)->selectRaw('count(*)'), $table);
        }
        $counts = $query->first();
        $counters = [];
        foreach ($definitions as [$table, $label, $href]) {
            $counters[] = ['key' => $table, 'label' => $label, 'href' => $href, 'value' => (int) $counts->$table];
        }

        return response()->json(['data' => compact('counters')])->header('Cache-Control', 'private, no-store');
    }
}
