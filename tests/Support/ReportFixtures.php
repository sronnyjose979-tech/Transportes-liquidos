<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Workshop\Application\WorkshopAuthorizations;
use App\Modules\Workshop\Application\WorkshopCore;
use App\Modules\Workshop\Application\WorkshopExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Existing authorized workflow, with two activities and a historical correction. */
final class ReportFixtures
{
    public static function workshop(User $admin, string $asset): string
    {
        $core = app(WorkshopCore::class);
        $order = $core->execute($admin, 'order-create', null, (string) Str::uuid(), [
            'fleet_asset_id' => $asset, 'kind' => 'corrective', 'description' => 'Reparación sintética para reportes',
            'priority' => 'normal', 'created_at' => now()->toIso8601String(), 'reason' => 'Prueba de consulta',
        ])['order_id'];
        $diagnosis = $core->execute($admin, 'diagnosis-record', $order, (string) Str::uuid(), [
            'problem' => 'Falla sintética', 'probable_cause' => 'Desgaste', 'proposed_repair' => 'Reparar',
            'occurred_at' => now()->toIso8601String(), 'reason' => 'Diagnóstico sintético',
        ])['diagnosis_id'];
        $authorizations = app(WorkshopAuthorizations::class);
        $scope = $authorizations->requestScope($admin, $order, (string) Str::uuid(), [
            'diagnosis_id' => $diagnosis, 'description' => 'Una reparación', 'reason' => 'Alcance sintético',
            'tasks' => [['line_number' => 1, 'description' => 'Diagnóstico técnico'], ['line_number' => 2, 'description' => 'Reparar componente']],
        ]);
        $decision = $authorizations->decide($admin, $scope['request_id'], (string) Str::uuid(), 'approved', 'Revisión sintética');
        $authorizations->applyScope($admin, $decision['decision_id'], (string) Str::uuid());
        $execution = app(WorkshopExecution::class);
        $first = null;
        foreach (DB::table('workshop_scope_tasks')->where('scope_id', $scope['scope_id'])->orderBy('line_number')->get() as $task) {
            $data = ['task_id' => $task->id, 'description' => 'Actividad registrada', 'reason' => 'Prueba sintética',
                'occurred_at' => now()->toIso8601String(), 'version' => (int) DB::table('workshop_work_orders')->where('id', $order)->value('version')];
            $activity = $execution->recordActivity($admin, (string) Str::uuid(), $data);
            $first ??= [$data, $activity];
        }
        $execution->recordActivity($admin, (string) Str::uuid(), [...$first[0], 'description' => 'Rectificación sin borrar historial',
            'previous_activity_id' => $first[1]['activity_id'], 'occurred_at' => now()->toIso8601String()]);

        return $order;
    }
}
