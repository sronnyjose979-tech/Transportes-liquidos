<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkshopCore
{
    public function __construct(private readonly WorkshopCommands $commands, private readonly WorkshopAccess $access) {}

    public function execute(User $actor, string $action, ?string $target, string $key, array $data): array
    {
        return $this->commands->run($actor, 'api_'.$action.':'.($target ?? $data['fleet_asset_id'] ?? 'new'), $key, $data,
            function (User $fresh) use ($action, $target, $data): void {
                if (str_starts_with($action, 'diagnosis-')) {
                    $order = $action === 'diagnosis-record' ? $target : $this->row('workshop_diagnoses', $target)['work_order_id'];
                    $this->access->writeOrder($fresh, $order);
                } elseif (str_starts_with($action, 'report-') && in_array($action, ['report-create', 'report-revise'])) {
                    $this->commands->authorizeRoles($fresh, ['administrator', 'secretary', 'mechanic']);
                    if ($action === 'report-create') {
                        DB::table('fleet_assets')->where('id', $data['fleet_asset_id'])->firstOrFail();
                    } else {
                        $this->access->readReport($fresh, $target);
                        $report = $this->row('workshop_reports', $target);
                        if (! $this->access->isOffice($fresh) && (int) $report['recorded_by_id'] !== (int) $fresh->id) {
                            throw new AuthorizationException('Solo el registrador puede revisar su reporte.');
                        }
                    }
                } else {
                    $this->access->office($fresh);
                }
            },
            function (User $fresh, string $operationId) use ($action, $target, $data): array {
                $id = (string) Str::uuid();
                $operation = ['reason' => $data['reason']];
                $before = null;
                switch ($action) {
                    case 'order-create':
                        $table = 'workshop_work_orders';
                        $values = array_intersect_key($data, array_flip(['fleet_asset_id', 'kind', 'description', 'priority', 'created_at']));
                        $values += ['status' => 'pending_diagnosis', 'created_by_id' => $fresh->id];
                        $operation += ['work_order_id' => $id, 'operation_type' => 'order_create'];
                        $result = ['order_id' => $id, 'version' => 1];
                        break;
                    case 'order-update':
                        $table = 'workshop_work_orders';
                        $id = $target;
                        $before = $this->row($table, $id);
                        if (in_array($before['status'], ['finished', 'closed', 'cancelled'], true)) {
                            throw ValidationException::withMessages(['order' => 'Una orden terminal requiere rectificación aprobada.']);
                        }
                        if ($before['version'] !== $data['version']) {
                            throw ValidationException::withMessages(['version' => 'Versión obsoleta.']);
                        }
                        $values = array_intersect_key($data, array_flip(['description', 'priority']));
                        $values['version'] = $before['version'] + 1;
                        $operation += ['work_order_id' => $id, 'operation_type' => 'order_update', 'from_version' => $before['version'], 'to_version' => $values['version'], 'before_values' => $before];
                        $result = ['order_id' => $id, 'version' => $values['version']];
                        break;
                    case 'report-create':
                    case 'report-revise':
                        $table = 'workshop_reports';
                        $values = array_intersect_key($data, array_flip(['fleet_asset_id', 'trip_event_id', 'description', 'occurred_at', 'reason']));
                        $values += ['reporter_id' => null, 'recorded_by_id' => $fresh->id, 'root_record_id' => $id, 'revision' => 1];
                        if ($action === 'report-revise') {
                            $before = $this->row($table, $target);
                            $values['fleet_asset_id'] = $before['fleet_asset_id'];
                            $values['reporter_id'] = $before['reporter_id'];
                            $values['trip_event_id'] = $before['trip_event_id'];
                            $values['previous_report_id'] = $target;
                            $values['root_record_id'] = $before['root_record_id'];
                            $values['revision'] = $before['revision'] + 1;
                        }
                        $operation += ['report_id' => $id, 'operation_type' => $action === 'report-create' ? 'report_create' : 'report_revise'];
                        $result = ['report_id' => $id];
                        break;
                    case 'diagnosis-record':
                    case 'diagnosis-revise':
                        $table = 'workshop_diagnoses';
                        $values = array_intersect_key($data, array_flip(['mechanic_assignment_id', 'problem', 'probable_cause', 'proposed_repair', 'occurred_at', 'reason']));
                        $values += ['work_order_id' => $target, 'actor_id' => $fresh->id, 'root_record_id' => $id, 'revision' => 1];
                        if ($action === 'diagnosis-revise') {
                            $before = $this->row($table, $target);
                            $values['work_order_id'] = $before['work_order_id'];
                            $values['mechanic_assignment_id'] = $before['mechanic_assignment_id'];
                            $values['previous_diagnosis_id'] = $target;
                            $values['root_record_id'] = $before['root_record_id'];
                            $values['revision'] = $before['revision'] + 1;
                        }
                        $order = $this->row('workshop_work_orders', $values['work_order_id']);
                        if (in_array($order['status'], ['finished', 'closed', 'cancelled'], true)) {
                            throw ValidationException::withMessages(['order' => 'Orden terminal: requiere reapertura autorizada.']);
                        }
                        $operation += ['work_order_id' => $values['work_order_id'], 'diagnosis_id' => $id, 'operation_type' => $action === 'diagnosis-record' ? 'diagnosis_record' : 'diagnosis_revise'];
                        $result = ['diagnosis_id' => $id];
                        break;
                    case 'mechanic-assign':
                        $table = 'workshop_mechanic_assignments';
                        $values = ['work_order_id' => $target, 'employee_id' => $data['employee_id'], 'starts_at' => $data['starts_at'], 'assigned_by_id' => $fresh->id, 'reason' => $data['reason']];
                        $operation += ['work_order_id' => $target, 'mechanic_assignment_id' => $id, 'operation_type' => 'mechanic_assign'];
                        $result = ['assignment_id' => $id];
                        break;
                    case 'mechanic-release':
                        $before = $this->row('workshop_mechanic_assignments', $target);
                        $table = 'workshop_mechanic_releases';
                        $values = ['assignment_id' => $target, 'ends_at' => $data['ends_at'], 'recorded_by_id' => $fresh->id, 'reason' => $data['reason']];
                        $operation += ['work_order_id' => $before['work_order_id'], 'mechanic_assignment_id' => $target, 'mechanic_release_id' => $id, 'operation_type' => 'mechanic_release'];
                        $result = ['release_id' => $id];
                        break;
                    case 'report-link':
                        $table = 'workshop_report_links';
                        $values = ['work_order_id' => $target, 'report_id' => $data['report_id'], 'linked_by_id' => $fresh->id, 'reason' => $data['reason']];
                        $operation += ['work_order_id' => $target, 'report_link_id' => $id, 'operation_type' => 'report_link_create'];
                        $result = ['link_id' => $id];
                        break;
                    case 'report-link-invalidate':
                    case 'admission-link-invalidate':
                        $table = $action === 'report-link-invalidate' ? 'workshop_report_links' : 'workshop_order_admission_links';
                        $before = $this->row($table, $target);
                        $field = $action === 'report-link-invalidate' ? 'report_link_id' : 'order_admission_link_id';

                        return ['operation' => $operation + ['work_order_id' => $before['work_order_id'], $field => $target, 'operation_type' => $action === 'report-link-invalidate' ? 'report_link_invalidate' : 'order_admission_link_invalidate', 'before_values' => $before, 'after_values' => ['invalidated' => true]], 'result' => ['link_id' => $target, 'invalidated' => true]];
                    default:
                        throw ValidationException::withMessages(['action' => 'Operación no soportada.']);
                }
                if ($action === 'order-update') {
                    DB::table($table)->where('id', $id)->update($values);
                } else {
                    DB::table($table)->insert(['id' => $id] + $values);
                }
                $operation['after_values'] = $this->row($table, $id);

                return ['operation' => $operation, 'result' => $result];
            });
    }

    private function row(string $table, string $id): array
    {
        $row = DB::selectOne("SELECT to_jsonb(t)::text AS snapshot FROM {$table} t WHERE id=? FOR UPDATE", [$id]);
        abort_if($row === null, 404);

        return json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
    }
}
