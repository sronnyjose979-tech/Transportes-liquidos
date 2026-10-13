<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkshopCosts
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    public function record(User $actor, string $orderId, string $key, array $data): array
    {
        $allowed = ['scope_task_id', 'scope_part_id', 'usage_id', 'external_repair_id', 'cost_kind', 'basis', 'amount', 'currency', 'source_attachment_id', 'covered_by_cost_id', 'description', 'occurred_at', 'reason'];
        if (array_diff(array_keys($data), $allowed) !== [] || (isset($data['amount']) && ! is_string($data['amount']))) {
            throw ValidationException::withMessages(['content' => 'Campos permitidos e importe decimal como texto requeridos.']);
        }

        return $this->commands->run($actor, 'cost_record:'.$orderId, $key, $data,
            fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator', 'secretary']),
            function (User $fresh) use ($orderId, $data): array {
                DB::table('workshop_work_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
                $id = (string) Str::uuid();
                $amount = $data['amount'] ?? null;
                unset($data['amount']);
                $row = array_merge($data, ['id' => $id, 'work_order_id' => $orderId, 'actor_id' => $fresh->id]);
                DB::select('SELECT workshop_write_cost(?::jsonb, ?::text)', [json_encode($row, JSON_THROW_ON_ERROR), $amount]);
                $snapshot = DB::selectOne('SELECT to_jsonb(t)::text AS data FROM workshop_cost_records t WHERE id=?', [$id])->data;

                return ['operation' => ['work_order_id' => $orderId, 'operation_type' => 'cost_record', 'cost_record_id' => $id, 'after_values' => $snapshot, 'reason' => $data['reason'], 'occurred_at' => $data['occurred_at']], 'result' => ['cost_record_id' => $id]];
            });
    }

    public function revise(User $actor, string $decisionId, string $key): array
    {
        return app(WorkshopMaterialCorrections::class)->apply($actor, $decisionId, $key, 'workshop_cost_records');
    }

    public function totals(User $actor, string $orderId): array
    {
        $fresh = User::query()->findOrFail($actor->id);
        $this->commands->authorizeRoles($fresh, ['administrator', 'secretary']);

        return array_map(fn ($row) => (array) $row, DB::select('SELECT c.basis,c.currency,sum(c.amount)::text AS amount FROM workshop_cost_records c WHERE c.work_order_id=? AND c.cost_kind IS NOT NULL AND c.amount IS NOT NULL AND NOT EXISTS(SELECT 1 FROM workshop_cost_records n WHERE n.previous_cost_id=c.id) AND NOT EXISTS(SELECT 1 FROM workshop_cost_records p WHERE p.root_record_id=(SELECT root_record_id FROM workshop_cost_records WHERE id=c.covered_by_cost_id) AND NOT EXISTS(SELECT 1 FROM workshop_cost_records n WHERE n.previous_cost_id=p.id)) GROUP BY c.basis,c.currency ORDER BY c.basis,c.currency', [$orderId]));
    }
}
