<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Modules\Workshop\Application\WorkshopAccess;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopAvailability;
use App\Modules\Workshop\Application\WorkshopExecution;
use App\Modules\Workshop\Application\WorkshopOrderRequests;
use App\Modules\Workshop\Http\Requests\WorkshopProcessRequest;
use App\Modules\Workshop\Http\Requests\WorkshopQueryRequest;
use App\Modules\Workshop\Http\Resources\WorkshopResultResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkshopProcessController
{
    public function __construct(private readonly WorkshopAccess $access, private readonly WorkshopAuthorizations $authorizations,
        private readonly WorkshopAvailability $availability, private readonly WorkshopExecution $execution,
        private readonly WorkshopOrderRequests $orderRequests) {}

    private function orderRelation(string $table, string $id, string $order, string $field = 'work_order_id'): void
    {
        if (! DB::table($table)->where('id', $id)->where($field, $order)->exists()) {
            throw ValidationException::withMessages(['resource' => 'Referencia incompatible con la orden.']);
        }
    }

    public function order(WorkshopProcessRequest $request, string $order): WorkshopResultResource
    {
        $action = $request->route('action');
        $actor = $request->user();
        $this->access->writeOrder($actor, $order, in_array($action, ['scope', 'order-request'], true) ? ['administrator', 'secretary', 'mechanic'] : ['administrator', 'mechanic']);
        $data = $request->validated();
        $key = $data['request_key'];
        unset($data['request_key']);
        foreach (['diagnosis_id' => 'workshop_diagnoses', 'mechanic_assignment_id' => 'workshop_mechanic_assignments', 'previous_scope_id' => 'workshop_scopes', 'previous_event_id' => 'workshop_time_events', 'previous_request_id' => 'workshop_authorization_requests'] as $field => $table) {
            if (isset($data[$field])) {
                $this->orderRelation($table, $data[$field], $order);
            }
        }
        foreach (['task_id' => 'workshop_scope_tasks', 'previous_activity_id' => 'workshop_activities'] as $field => $table) {
            if (isset($data[$field])) {
                $query = DB::table($table.' as t');
                if ($field === 'previous_activity_id') {
                    $query->join('workshop_scope_tasks as task', 'task.id', '=', 't.task_id')->join('workshop_scopes as s', 's.id', '=', 'task.scope_id');
                } else {
                    $query->join('workshop_scopes as s', 's.id', '=', 't.scope_id');
                }
                if (! $query->where('t.id', $data[$field])->where('s.work_order_id', $order)->exists()) {
                    throw ValidationException::withMessages([$field => 'Referencia incompatible con la orden.']);
                }
            }
        }
        if (isset($data['decision_id'])) {
            $this->decisionOrder($data['decision_id'], $order);
        }
        if ($action === 'scope') {
            $lines = array_column($data['tasks'], 'line_number');
            foreach ($data['parts'] ?? [] as $part) {
                if (isset($part['task_line_number']) && ! in_array($part['task_line_number'], $lines, true)) {
                    throw ValidationException::withMessages(['parts' => 'La tarea de repuesto no existe en el alcance.']);
                }
            }
        }
        $result = match ($action) {
            'scope' => $this->authorizations->requestScope($actor, $order, $key, $data),
            'order-request' => $this->orderRequests->request($actor, $order, $key, $data),
            'activity' => $this->execution->recordActivity($actor, $key, $data),
            'time' => $this->execution->recordTime($actor, $key, $data + ['work_order_id' => $order]),
            'finish', 'close' => $this->execution->transition($actor, $order, $key, $data + ['status' => $action === 'finish' ? 'finished' : 'closed']),
        };

        return new WorkshopResultResource($result);
    }

    private function decisionOrder(string $decision, string $order): void
    {
        if (! DB::table('workshop_authorization_decisions as d')->join('workshop_authorization_requests as q', 'q.id', '=', 'd.request_id')
            ->where('d.id', $decision)->where('q.work_order_id', $order)->exists()) {
            throw ValidationException::withMessages(['decision_id' => 'Decisión de otra orden.']);
        }
    }

    public function decide(WorkshopProcessRequest $request, string $authorization): WorkshopResultResource
    {
        $this->access->admin($request->user());
        $row = DB::table('workshop_authorization_requests')->where('id', $authorization)->firstOrFail();
        $this->requestAccess($request, $row);
        $data = $request->validated();

        return new WorkshopResultResource($this->authorizations->decide($request->user(), $authorization, $data['request_key'], $data['decision'], $data['reason']));
    }

    private function requestAccess(Request $request, object $row): void
    {
        if ($row->work_order_id) {
            $this->access->readOrder($request->user(), $row->work_order_id);
        } else {
            $asset = DB::table('workshop_admissions')->where('id', $row->admission_id)->value('fleet_asset_id');
            abort_unless($asset, 404);
            $this->access->readAsset($request->user(), $asset);
        }
    }

    public function apply(WorkshopProcessRequest $request, string $decision): WorkshopResultResource
    {
        $this->access->admin($request->user());
        $row = DB::table('workshop_authorization_decisions as d')->join('workshop_authorization_requests as q', 'q.id', '=', 'd.request_id')->where('d.id', $decision)->select('q.*')->firstOrFail();
        $this->requestAccess($request, $row);
        $key = $request->validated('request_key');

        return new WorkshopResultResource(match ($request->route('action')) {
            'scope-apply' => $this->authorizations->applyScope($request->user(), $decision, $key),
            'order-apply' => $this->authorizations->applyOrderDecision($request->user(), $decision, $key),
            'exit' => $this->availability->exit($request->user(), $decision, $key),
        });
    }

    public function asset(WorkshopProcessRequest $request, string $asset): WorkshopResultResource
    {
        $this->access->office($request->user());
        $this->access->readAsset($request->user(), $asset);
        $data = $request->validated();
        $key = $data['request_key'];
        unset($data['request_key']);
        foreach (['work_order_id' => 'workshop_work_orders', 'admission_id' => 'workshop_admissions'] as $field => $table) {
            if (isset($data[$field]) && ! DB::table($table)->where('id', $data[$field])->where('fleet_asset_id', $asset)->exists()) {
                throw ValidationException::withMessages([$field => 'Referencia de otro activo.']);
            }
        }

        return new WorkshopResultResource(match ($request->route('action')) {
            'admit' => $this->availability->admit($request->user(), $asset, $key, $data),
            'historical' => $this->availability->captureHistorical($request->user(), $asset, $key, $data['reason']),
            'impediment' => $this->availability->recordImpediment($request->user(), $asset, $key, $data),
        });
    }

    public function resolve(WorkshopProcessRequest $request, string $impediment): WorkshopResultResource
    {
        $this->access->admin($request->user());
        $row = DB::table('fleet_operating_impediments')->where('id', $impediment)->firstOrFail();
        $this->access->readAsset($request->user(), $row->fleet_asset_id);

        return new WorkshopResultResource($this->availability->resolve($request->user(), $impediment, $request->validated('request_key'), $request->validated('reason')));
    }

    public function requestExit(WorkshopProcessRequest $request, string $admission): WorkshopResultResource
    {
        $this->access->admin($request->user());
        $row = DB::table('workshop_admissions')->where('id', $admission)->firstOrFail();
        $this->access->readAsset($request->user(), $row->fleet_asset_id);
        $data = $request->validated();
        $key = $data['request_key'];
        unset($data['request_key']);
        foreach ($data['resolve_ids'] as $id) {
            if (! DB::table('fleet_operating_impediments')->where('id', $id)->where('fleet_asset_id', $row->fleet_asset_id)->exists()) {
                throw ValidationException::withMessages(['resolve_ids' => 'Causa de otro activo.']);
            }
        }

        return new WorkshopResultResource($this->availability->requestExit($request->user(), $admission, $key, $data));
    }

    public function times(WorkshopQueryRequest $request, string $order): WorkshopResultResource
    {
        $this->access->readOrder($request->user(), $order);

        return new WorkshopResultResource($this->execution->times($request->user(), $order));
    }
}
