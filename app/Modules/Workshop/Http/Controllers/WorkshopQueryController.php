<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Modules\Workshop\Http\Requests\WorkshopQueryRequest;
use App\Modules\Workshop\Http\Resources\WorkshopRecordResource;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WorkshopQueryController extends Controller
{
    public const RECORDS = [
        'orders' => ['workshop_work_orders', 'created_at', null],
        'reports' => ['workshop_reports', 'occurred_at', 'previous_report_id'],
        'diagnoses' => ['workshop_diagnoses', 'occurred_at', 'previous_diagnosis_id'],
        'admissions' => ['workshop_admissions', 'admitted_at', null],
        'mechanic-assignments' => ['workshop_mechanic_assignments', 'starts_at', null],
        'mechanic-releases' => ['workshop_mechanic_releases', 'ends_at', null],
        'report-links' => ['workshop_report_links', 'registered_at', null],
        'admission-links' => ['workshop_order_admission_links', 'registered_at', null],
        'scopes' => ['workshop_scopes', 'registered_at', 'previous_scope_id'],
        'scope-tasks' => ['workshop_scope_tasks', 'id', null],
        'scope-parts' => ['workshop_scope_parts', 'id', null],
        'authorization-requests' => ['workshop_authorization_requests', 'registered_at', null],
        'authorization-decisions' => ['workshop_authorization_decisions', 'registered_at', null],
        'authorization-applications' => ['workshop_authorization_applications', 'registered_at', null],
        'activities' => ['workshop_activities', 'occurred_at', 'previous_activity_id'],
        'time-events' => ['workshop_time_events', 'occurred_at', 'previous_event_id'],
        'deliveries' => ['workshop_part_deliveries', 'occurred_at', 'previous_delivery_id'],
        'usages' => ['workshop_part_usages', 'occurred_at', 'previous_usage_id'],
        'material-needs' => ['workshop_material_needs', 'occurred_at', 'previous_need_id'],
        'external-repairs' => ['workshop_external_repairs', 'performed_at', 'previous_record_id'],
        'costs' => ['workshop_cost_records', 'occurred_at', 'previous_cost_id'],
        'impediments' => ['fleet_operating_impediments', 'occurred_at', null],
        'impediment-resolutions' => ['fleet_impediment_resolutions', 'resolved_at', null],
        'exits' => ['workshop_admission_exits', 'exited_at', null],
        'warnings' => ['workshop_warnings', 'registered_at', null],
    ];

    public function __construct(private readonly WorkshopAccess $access) {}

    public function index(WorkshopQueryRequest $request, string $kind): JsonResponse
    {
        [$table, $time, $previous] = self::RECORDS[$kind];
        $query = $this->query($request->user(), $kind);
        $this->filters($query, $request, $kind, $time, $previous);

        return WorkshopRecordResource::collection($query->orderBy('record.'.$time)->when($time !== 'id', fn (Builder $q) => $q->orderBy('record.id'))->paginate($request->integer('per_page', 25)))->response();
    }

    public function show(WorkshopQueryRequest $request, string $id, string $kind): WorkshopRecordResource
    {
        [$table] = self::RECORDS[$kind];
        DB::table($table)->where('id', $id)->firstOrFail();
        $query = $this->query($request->user(), $kind)->where('record.id', $id);
        $record = $query->first();
        abort_if($record === null, 403, 'Registro fuera del alcance autorizado.');

        return new WorkshopRecordResource($record);
    }

    public function asset(WorkshopQueryRequest $request, string $asset): WorkshopRecordResource
    {
        $this->access->readAsset($request->user(), $asset);

        return new WorkshopRecordResource(DB::table('fleet_assets')->where('id', $asset)->select(['id', 'code', 'type', 'service_status'])->firstOrFail());
    }

    public function forOrder(WorkshopQueryRequest $request, string $order, string $kind): JsonResponse
    {
        $this->access->readOrder($request->user(), $order);

        return $this->index($request, $kind);
    }

    private function query(User $actor, string $kind): Builder
    {
        [$table, , $previous] = self::RECORDS[$kind];
        $this->access->module($actor);
        $query = DB::table($table.' as record')->select('record.*');
        $order = 'record.work_order_id';
        switch ($kind) {
            case 'orders': $order = 'record.id';
                break;
            case 'costs': $this->access->office($actor);
                break;
            case 'activities':
                $query->join('workshop_scope_tasks as task', 'task.id', '=', 'record.task_id')->join('workshop_scopes as scope', 'scope.id', '=', 'task.scope_id');
                $order = 'scope.work_order_id';
                break;
            case 'scope-parts': case 'scope-tasks':
                $query->join('workshop_scopes as scope', 'scope.id', '=', 'record.scope_id');
                $order = 'scope.work_order_id';
                break;
            case 'deliveries':
                $query->join('workshop_scope_parts as part', 'part.id', '=', 'record.scope_part_id')->join('workshop_scopes as scope', 'scope.id', '=', 'part.scope_id');
                $order = 'scope.work_order_id';
                break;
            case 'usages':
                $query->join('workshop_part_deliveries as delivery', 'delivery.id', '=', 'record.delivery_id')->join('workshop_scope_parts as part', 'part.id', '=', 'delivery.scope_part_id')->join('workshop_scopes as scope', 'scope.id', '=', 'part.scope_id');
                $order = 'scope.work_order_id';
                break;
            case 'mechanic-releases':
                $query->join('workshop_mechanic_assignments as assignment', 'assignment.id', '=', 'record.assignment_id');
                $order = 'assignment.work_order_id';
                break;
            case 'reports':
                if (! $this->access->isOffice($actor)) {
                    $query->where(fn (Builder $q) => $q->where('record.recorded_by_id', $actor->id)->orWhere('record.reporter_id', $actor->id)->orWhereExists(function (Builder $link) use ($actor): void {
                        $link->selectRaw('1')->from('workshop_report_links as visibility_link')->join('workshop_work_orders as visibility_order', 'visibility_order.id', '=', 'visibility_link.work_order_id')->whereColumn('visibility_link.report_id', 'record.id');
                        $this->access->orders($actor, $link, 'visibility_order.id');
                    }));
                }
                $order = null;
                break;
            case 'authorization-applications':
                $query->join('workshop_authorization_decisions as decision', 'decision.id', '=', 'record.decision_id')->join('workshop_authorization_requests as authorization', 'authorization.id', '=', 'decision.request_id');
                $order = 'authorization.work_order_id';
                $this->authorizationVisibility($query, $actor, 'authorization');
                break;
            case 'authorization-decisions':
                $query->join('workshop_authorization_requests as authorization', 'authorization.id', '=', 'record.request_id');
                $order = 'authorization.work_order_id';
                $this->authorizationVisibility($query, $actor, 'authorization');
                break;
            case 'authorization-requests':
                $this->authorizationVisibility($query, $actor, 'record');
                break;
            case 'exits': case 'warnings':
                $query->join('workshop_admissions as admission', 'admission.id', '=', 'record.admission_id');
                $this->admissionsVisibility($query, $actor, 'admission.id');
                $order = null;
                break;
            case 'admissions':
                $this->admissionsVisibility($query, $actor, 'record.id');
                $query->selectRaw('NOT EXISTS(SELECT 1 FROM workshop_admission_exits exit WHERE exit.admission_id=record.id) AS is_open');
                $order = null;
                break;
            case 'impediment-resolutions':
                $query->join('fleet_operating_impediments as cause', 'cause.id', '=', 'record.impediment_id');
                $this->causesVisibility($query, $actor, 'cause');
                $order = null;
                break;
            case 'impediments':
                $this->causesVisibility($query, $actor, 'record');
                $query->selectRaw('NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions resolution WHERE resolution.impediment_id=record.id) AS is_open');
                $order = null;
                break;
        }
        if ($order !== null) {
            $this->access->orders($actor, $query, $order);
        }
        if ($previous !== null && $kind !== 'scopes') {
            $query->selectRaw("NOT EXISTS(SELECT 1 FROM {$table} successor WHERE successor.{$previous}=record.id) AS is_effective");
        }
        if ($kind === 'scopes') {
            $query->selectRaw('workshop_scope_is_effective(record.id) AS is_effective')->selectRaw('EXISTS(SELECT 1 FROM workshop_authorization_requests ar JOIN workshop_authorization_decisions ad ON ad.request_id=ar.id JOIN workshop_authorization_applications aa ON aa.decision_id=ad.id WHERE ar.scope_id=record.id) AS is_applied');
        }
        if ($kind === 'mechanic-assignments') {
            $query->selectRaw('EXISTS(SELECT 1 FROM workshop_mechanic_releases release WHERE release.assignment_id=record.id) AS is_released');
            $query->join('employees as member', 'member.id', '=', 'record.employee_id')
                ->addSelect('member.name as employee_name')
                ->selectRaw('member.user_id = ? AS is_current_actor', [$actor->id])
                ->selectRaw('member.is_active AND record.starts_at <= CURRENT_TIMESTAMP AND NOT EXISTS(SELECT 1 FROM workshop_mechanic_releases release WHERE release.assignment_id=record.id) AS is_current');
        }

        return $query;
    }

    private function authorizationVisibility(Builder $query, User $actor, string $alias): void
    {
        if (! $this->access->isOffice($actor)) {
            $content = $query->getGrammar()->wrap($alias.'.content');
            $query->whereNotNull($alias.'.work_order_id')->whereRaw("COALESCE({$content}->>'fact_table','') NOT IN ('workshop_cost_records','workshop_external_repairs','workshop_attachments')");
        }
    }

    private function admissionsVisibility(Builder $query, User $actor, string $column): void
    {
        if (! $this->access->isOffice($actor)) {
            $query->whereExists(function (Builder $link) use ($actor, $column): void {
                $link->selectRaw('1')->from('workshop_order_admission_links as visibility_link')->join('workshop_work_orders as visibility_order', 'visibility_order.id', '=', 'visibility_link.work_order_id')->whereColumn('visibility_link.admission_id', $column);
                $this->access->orders($actor, $link, 'visibility_order.id');
            });
        }
    }

    private function causesVisibility(Builder $query, User $actor, string $alias): void
    {
        if (! $this->access->isOffice($actor)) {
            $query->whereExists(function (Builder $orders) use ($actor, $alias): void {
                $orders->selectRaw('1')->from('workshop_work_orders as visibility_order')->whereColumn('visibility_order.fleet_asset_id', $alias.'.fleet_asset_id')->where(fn (Builder $q) => $q->whereNull($alias.'.work_order_id')->orWhereColumn('visibility_order.id', $alias.'.work_order_id'));
                $this->access->orders($actor, $orders, 'visibility_order.id');
            });
        }
    }

    private function filters(Builder $query, WorkshopQueryRequest $request, string $kind, string $time, ?string $previous): void
    {
        [$table] = self::RECORDS[$kind];
        foreach (['status', 'priority', 'kind', 'employee_id'] as $field) {
            if ($request->has($field)) {
                $query->where('record.'.$field, $request->validated($field));
            }
        }
        foreach (['fleet_asset_id', 'work_order_id'] as $field) {
            if ($request->has($field) || ($field === 'work_order_id' && $request->route('order') !== null)) {
                $value = $field === 'work_order_id' ? ($request->route('order') ?? $request->validated($field)) : $request->validated($field);
                if ($field === 'work_order_id') {
                    $this->access->readOrder($request->user(), $value);
                } else {
                    $this->access->readAsset($request->user(), $value);
                }
                if ($field === 'fleet_asset_id') {
                    $assetColumn = match ($kind) {
                        'reports','admissions','impediments' => 'record.fleet_asset_id',
                        'exits','warnings' => 'admission.fleet_asset_id',
                        'impediment-resolutions' => 'cause.fleet_asset_id',
                        default => null,
                    };
                    if ($assetColumn !== null) {
                        $query->where($assetColumn, $value);

                        continue;
                    }
                    if (in_array($kind, ['authorization-requests', 'authorization-decisions', 'authorization-applications'], true)) {
                        $alias = $kind === 'authorization-requests' ? 'record' : 'authorization';
                        $query->where(fn (Builder $match) => $match->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('workshop_work_orders as filter_order')->whereColumn('filter_order.id', $alias.'.work_order_id')->where('filter_order.fleet_asset_id', $value))->orWhereExists(fn (Builder $q) => $q->selectRaw('1')->from('workshop_admissions as filter_admission')->whereColumn('filter_admission.id', $alias.'.admission_id')->where('filter_admission.fleet_asset_id', $value)));

                        continue;
                    }
                }
                // Resolve scope filters relationally instead of adding denormalized columns.
                $query->whereExists(function (Builder $match) use ($kind, $field, $value): void {
                    $match->selectRaw('1')->from('workshop_work_orders as filter_order');
                    if ($kind === 'orders') {
                        $match->whereColumn('filter_order.id', 'record.id');
                    } elseif (in_array($kind, ['activities', 'deliveries', 'usages', 'scope-parts', 'scope-tasks'], true)) {
                        $match->whereColumn('filter_order.id', 'scope.work_order_id');
                    } elseif ($kind === 'mechanic-releases') {
                        $match->whereColumn('filter_order.id', 'assignment.work_order_id');
                    } elseif (in_array($kind, ['authorization-decisions', 'authorization-applications'], true)) {
                        $match->whereColumn('filter_order.id', 'authorization.work_order_id');
                    } elseif ($kind === 'reports') {
                        $match->whereColumn('filter_order.fleet_asset_id', 'record.fleet_asset_id');
                        if ($field === 'work_order_id') {
                            $match->whereExists(fn (Builder $links) => $links->selectRaw('1')->from('workshop_report_links as filter_link')->whereColumn('filter_link.report_id', 'record.id')->whereColumn('filter_link.work_order_id', 'filter_order.id'));
                        }
                    } elseif ($kind === 'admissions') {
                        $match->whereColumn('filter_order.fleet_asset_id', 'record.fleet_asset_id');
                        if ($field === 'work_order_id') {
                            $match->whereExists(fn (Builder $links) => $links->selectRaw('1')->from('workshop_order_admission_links as filter_link')->whereColumn('filter_link.admission_id', 'record.id')->whereColumn('filter_link.work_order_id', 'filter_order.id'));
                        }
                    } elseif (in_array($kind, ['exits', 'warnings'], true)) {
                        $match->whereExists(fn (Builder $links) => $links->selectRaw('1')->from('workshop_order_admission_links as filter_link')->whereColumn('filter_link.admission_id', 'admission.id')->whereColumn('filter_link.work_order_id', 'filter_order.id'));
                    } elseif ($kind === 'impediment-resolutions') {
                        $match->whereColumn('filter_order.fleet_asset_id', 'cause.fleet_asset_id');
                    } elseif ($kind === 'impediments') {
                        $match->whereColumn('filter_order.fleet_asset_id', 'record.fleet_asset_id');
                    } else {
                        $match->whereColumn('filter_order.id', 'record.work_order_id');
                    }
                    $match->where($field === 'work_order_id' ? 'filter_order.id' : 'filter_order.fleet_asset_id', $value);
                });
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($request->has($field)) {
                $query->where('record.'.$time, $operator, $request->validated($field));
            }
        }
        if ($request->boolean('effective') && $previous !== null) {
            if ($kind === 'scopes') {
                $query->whereRaw('workshop_scope_is_effective(record.id)');
            } else {
                $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($table.' as successor')->whereColumn('successor.'.$previous, 'record.id'));
            }
        }
        if ($kind === 'scopes' && $request->has('applied')) {
            $query->whereRaw(($request->boolean('applied') ? '' : 'NOT ').'EXISTS(SELECT 1 FROM workshop_authorization_requests ar JOIN workshop_authorization_decisions ad ON ad.request_id=ar.id JOIN workshop_authorization_applications aa ON aa.decision_id=ad.id WHERE ar.scope_id=record.id)');
        }
        if ($request->has('open')) {
            $sql = $kind === 'admissions' ? 'NOT EXISTS(SELECT 1 FROM workshop_admission_exits terminal WHERE terminal.admission_id=record.id)' : 'NOT EXISTS(SELECT 1 FROM fleet_impediment_resolutions terminal WHERE terminal.impediment_id=record.id)';
            $query->whereRaw(($request->boolean('open') ? '' : 'NOT ').'('.$sql.')');
        }
        if ($request->has('pending')) {
            $query->whereRaw(($request->boolean('pending') ? 'NOT ' : '').'EXISTS(SELECT 1 FROM workshop_authorization_decisions d WHERE d.request_id=record.id)');
        }
    }
}
