<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Http\Requests\InventoryWriteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InventoryController
{
    public function stock(Request $request): JsonResponse
    {
        $this->office($request);
        $this->pagination($request);
        $rows = DB::table('spare_parts as p')->select('p.id as spare_part_id', 'p.code', 'p.description', 'p.unit')->selectRaw("EXISTS(SELECT 1 FROM inventory_movements m WHERE m.spare_part_id=p.id AND m.kind='initial') AS initialized, (SELECT sum(warehouse_delta)::text FROM inventory_movements m WHERE m.spare_part_id=p.id) AS available_quantity, (SELECT sum(custody_delta)::text FROM inventory_movements m WHERE m.spare_part_id=p.id) AS custody_quantity, (SELECT sum(consumed_delta)::text FROM inventory_movements m WHERE m.spare_part_id=p.id) AS consumed_quantity")->orderBy('p.code')->paginate(min(max((int) $request->input('per_page', 50), 1), 100));

        return response()->json($rows);
    }

    public function movements(Request $request): JsonResponse
    {
        $this->office($request);
        $this->pagination($request);
        $query = DB::table('inventory_movements as movement')->join('users as actor', 'actor.id', '=', 'movement.actor_id')->select('movement.*', 'actor.name as actor_name')->orderByDesc('movement.registered_at')->orderBy('movement.id');
        $request->validate(['spare_part_id' => 'nullable|uuid', 'delivery_id' => 'nullable|uuid']);
        foreach (['spare_part_id', 'delivery_id'] as $field) {
            if ($request->filled($field)) {
                $query->where('movement.'.$field, $request->input($field));
            }
        }

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 50), 1), 100)));
    }

    public function custody(Request $request): JsonResponse
    {
        $this->pagination($request);
        $request->validate(['work_order_id' => 'nullable|uuid']);
        $query = DB::table('inventory_movements as m')->join('workshop_part_deliveries as d', 'd.id', '=', 'm.delivery_id')->join('workshop_scope_parts as p', 'p.id', '=', 'd.scope_part_id')->join('workshop_scopes as s', 's.id', '=', 'p.scope_id')->join('workshop_mechanic_assignments as a', 'a.id', '=', 'd.delivered_to_assignment_id')->join('employees as e', 'e.id', '=', 'a.employee_id')->join('spare_parts as catalog', 'catalog.id', '=', 'p.spare_part_id')->select('d.id as delivery_id', 'd.delivered_to_assignment_id as mechanic_assignment_id', 's.work_order_id', 'p.spare_part_id', 'p.unit_snapshot', 'e.id as employee_id', 'e.name as employee_name', 'catalog.code as part_code', 'p.description_snapshot as part_description')->selectRaw('sum(m.custody_delta)::text AS held_quantity, sum(m.consumed_delta)::text AS consumed_quantity')->groupBy('d.id', 's.work_order_id', 'p.spare_part_id', 'p.unit_snapshot', 'e.id', 'e.name', 'catalog.code', 'p.description_snapshot');
        if (! $request->user()->roles()->whereIn('name', ['administrator', 'secretary'])->exists()) {
            $query->where('e.user_id', $request->user()->id);
        }
        if ($request->filled('work_order_id')) {
            $query->where('s.work_order_id', $request->input('work_order_id'));
        }

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 50), 1), 100)));
    }

    public function corrections(Request $request): JsonResponse
    {
        $this->office($request);
        $this->pagination($request);

        return response()->json(DB::table('inventory_corrections as c')->leftJoin('inventory_decisions as d', 'd.correction_id', '=', 'c.id')->leftJoin('inventory_applications as a', 'a.decision_id', '=', 'd.id')->select('c.*', 'd.id as decision_id', 'd.decision', 'a.id as application_id')->orderByDesc('c.registered_at')->paginate(min(max((int) $request->input('per_page', 50), 1), 100)));
    }

    public function store(InventoryWriteRequest $request, InventoryLedger $ledger): JsonResponse
    {
        return response()->json(['data' => $ledger->correction($request->user(), $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function decide(InventoryWriteRequest $request, string $id, InventoryLedger $ledger): JsonResponse
    {
        return response()->json(['data' => $ledger->decide($request->user(), $id, $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function apply(InventoryWriteRequest $request, string $id, InventoryLedger $ledger): JsonResponse
    {
        return response()->json(['data' => $ledger->apply($request->user(), $id, $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function returns(InventoryWriteRequest $request, InventoryLedger $ledger): JsonResponse
    {
        return response()->json(['data' => $ledger->returnParts($request->user(), $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    private function pagination(Request $request): void
    {
        $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
    }

    private function office(Request $request): void
    {
        abort_unless($request->user()->roles()->whereIn('name', ['administrator', 'secretary'])->exists(), 403);
    }
}
