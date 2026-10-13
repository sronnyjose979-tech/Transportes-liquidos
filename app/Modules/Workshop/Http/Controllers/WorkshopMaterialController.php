<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Modules\Workshop\Application\WorkshopCosts;
use App\Modules\Workshop\Application\WorkshopMaterialCorrections;
use App\Modules\Workshop\Application\WorkshopMaterials;
use App\Modules\Workshop\Application\WorkshopRectifications;
use App\Modules\Workshop\Http\Requests\WorkshopMaterialRequest;
use App\Modules\Workshop\Http\Requests\WorkshopQueryRequest;
use App\Modules\Workshop\Http\Resources\WorkshopResultResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkshopMaterialController extends Controller
{
    public function __construct(private readonly WorkshopAccess $access, private readonly WorkshopMaterials $materials) {}

    private function record(WorkshopMaterialRequest $request, string $order, string $method, array $roles): WorkshopResultResource
    {
        $this->access->writeOrder($request->user(), $order, $roles);
        $data = $request->validated();
        $this->references($data, $order);
        $key = $data['request_key'];
        unset($data['request_key']);

        return new WorkshopResultResource($this->materials->{$method}($request->user(), $order, $key, $data));
    }

    private function references(array $data, string $order): void
    {
        foreach (['scope_task_id' => 'workshop_scope_tasks', 'scope_part_id' => 'workshop_scope_parts'] as $field => $table) {
            if (! empty($data[$field])) {
                DB::table($table.' as item')->join('workshop_scopes as scope', 'scope.id', '=', 'item.scope_id')->where('item.id', $data[$field])->where('scope.work_order_id', $order)->firstOrFail();
            }
        }
        foreach (['mechanic_assignment_id', 'delivered_to_assignment_id'] as $field) {
            if (! empty($data[$field])) {
                DB::table('workshop_mechanic_assignments')->where('id', $data[$field])->where('work_order_id', $order)->firstOrFail();
            }
        }
        if (! empty($data['delivery_id'])) {
            DB::table('workshop_part_deliveries as delivery')->join('workshop_scope_parts as part', 'part.id', '=', 'delivery.scope_part_id')->join('workshop_scopes as scope', 'scope.id', '=', 'part.scope_id')->where('delivery.id', $data['delivery_id'])->where('scope.work_order_id', $order)->firstOrFail();
        }
    }

    public function delivery(WorkshopMaterialRequest $request, string $order): WorkshopResultResource
    {
        return $this->record($request, $order, 'deliver', ['administrator', 'secretary']);
    }

    public function usage(WorkshopMaterialRequest $request, string $order): WorkshopResultResource
    {
        return $this->record($request, $order, 'usePart', ['administrator', 'mechanic']);
    }

    public function need(WorkshopMaterialRequest $request, string $order): WorkshopResultResource
    {
        return $this->record($request, $order, 'requestMaterial', ['administrator', 'secretary', 'mechanic']);
    }

    public function external(WorkshopMaterialRequest $request, string $order): WorkshopResultResource
    {
        return $this->record($request, $order, 'recordExternal', ['administrator']);
    }

    public function cost(WorkshopMaterialRequest $request, string $order, WorkshopCosts $costs): WorkshopResultResource
    {
        $this->access->writeOrder($request->user(), $order, ['administrator', 'secretary']);
        $data = $request->validated();
        $this->references($data, $order);
        $key = $data['request_key'];
        unset($data['request_key']);

        return new WorkshopResultResource($costs->record($request->user(), $order, $key, $data));
    }

    public function totals(WorkshopQueryRequest $request, string $order, WorkshopCosts $costs): WorkshopResultResource
    {
        $this->access->office($request->user());
        $this->access->readOrder($request->user(), $order);

        return new WorkshopResultResource($costs->totals($request->user(), $order));
    }

    public function rectify(WorkshopMaterialRequest $request, string $kind, string $fact, WorkshopRectifications $rectifications): WorkshopResultResource
    {
        $this->access->admin($request->user());
        $table = WorkshopMaterialRequest::TABLES[$kind] ?? abort(404);
        $original = DB::table($table)->where('id', $fact)->firstOrFail();
        $data = $request->validated();
        $executor = User::query()->findOrFail($data['executor_id']);
        $this->access->fresh($executor);
        $context = json_decode(DB::selectOne('SELECT workshop_fact_context(?::text, ?::uuid)::text AS data', [$table, $fact])->data, true, flags: JSON_THROW_ON_ERROR);
        $roles = match ($kind) {
            'usage', 'need', 'activity', 'time', 'attachment' => ['administrator', 'mechanic'], default => ['administrator']
        };
        if ($kind === 'attachment' && ($original->category === 'invoice' || $original->external_repair_id !== null || ($data['changes']['category'] ?? null) === 'invoice' || ! empty($data['changes']['external_repair_id']) || DB::table('workshop_cost_records')->where('source_attachment_id', $fact)->exists())) {
            $roles = ['administrator'];
        }
        $this->access->writeOrder($executor, $context['work_order_id'], $roles);

        return new WorkshopResultResource($rectifications->request($request->user(), $data['request_key'], $table, $fact, $data['version'], $data['changes'], $executor->id, $data['reason']));
    }

    public function revise(WorkshopMaterialRequest $request, string $decision, string $kind, WorkshopMaterialCorrections $corrections): WorkshopResultResource
    {
        $table = WorkshopMaterialRequest::TABLES[$kind] ?? abort(404);
        if (! in_array($kind, ['delivery', 'usage', 'external', 'cost', 'need'], true)) {
            throw ValidationException::withMessages(['kind' => 'Aplicación no compatible.']);
        }
        $context = DB::table('workshop_authorization_decisions as d')->join('workshop_authorization_requests as q', 'q.id', '=', 'd.request_id')->where('d.id', $decision)->select('q.work_order_id')->firstOrFail();
        $this->access->writeOrder($request->user(), $context->work_order_id, in_array($kind, ['usage', 'need'], true) ? ['administrator', 'mechanic'] : ['administrator']);

        return new WorkshopResultResource($corrections->apply($request->user(), $decision, $request->validated('request_key'), $table));
    }

    public function batch(WorkshopMaterialRequest $request, WorkshopMaterialCorrections $corrections): WorkshopResultResource
    {
        $this->access->admin($request->user());

        return new WorkshopResultResource($corrections->applyMany($request->user(), $request->validated('decisions'), $request->validated('request_key')));
    }
}
