<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Physical workshop handovers never create inventory stock movements. */
final class WorkshopMaterials
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    public function deliver(User $actor, string $orderId, string $key, array $data): array
    {
        return $this->record($actor, $orderId, $key, $data, 'delivery', 'workshop_part_deliveries', 'recorded_by_id', ['scope_part_id', 'delivered_to_assignment_id', 'quantity', 'occurred_at', 'reason'], ['administrator', 'secretary']);
    }

    public function usePart(User $actor, string $orderId, string $key, array $data): array
    {
        return $this->record($actor, $orderId, $key, $data, 'usage', 'workshop_part_usages', 'confirmed_by_id', ['delivery_id', 'mechanic_assignment_id', 'quantity', 'occurred_at', 'reason'], ['administrator', 'mechanic']);
    }

    public function requestMaterial(User $actor, string $orderId, string $key, array $data): array
    {
        return $this->record($actor, $orderId, $key, $data, 'material_need', 'workshop_material_needs', 'requested_by_id', ['scope_part_id', 'spare_part_id', 'quantity', 'description', 'occurred_at', 'reason'], ['administrator', 'secretary', 'mechanic']);
    }

    public function recordExternal(User $actor, string $orderId, string $key, array $data): array
    {
        return $this->record($actor, $orderId, $key, $data, 'external', 'workshop_external_repairs', 'recorded_by_id', ['scope_task_id', 'supplier_id', 'description', 'performed_at', 'reason'], ['administrator']);
    }

    public function reviseDelivery(User $actor, string $decisionId, string $key): array
    {
        return app(WorkshopMaterialCorrections::class)->apply($actor, $decisionId, $key, 'workshop_part_deliveries');
    }

    public function reviseUsage(User $actor, string $decisionId, string $key): array
    {
        return app(WorkshopMaterialCorrections::class)->apply($actor, $decisionId, $key, 'workshop_part_usages');
    }

    public function reviseExternal(User $actor, string $decisionId, string $key): array
    {
        return app(WorkshopMaterialCorrections::class)->apply($actor, $decisionId, $key, 'workshop_external_repairs');
    }

    public function reviseMaterialNeed(User $actor, string $decisionId, string $key): array
    {
        return app(WorkshopMaterialCorrections::class)->apply($actor, $decisionId, $key, 'workshop_material_needs');
    }

    private function record(User $actor, string $orderId, string $key, array $data, string $kind, string $table, string $actorField, array $fields, array $roles): array
    {
        if (array_diff(array_keys($data), $fields) !== []) {
            throw ValidationException::withMessages(['content' => 'Campos no permitidos.']);
        }
        if (isset($data['quantity']) && ! is_string($data['quantity'])) {
            throw ValidationException::withMessages(['quantity' => 'Cantidad decimal como texto requerida.']);
        }

        return $this->commands->run($actor, $kind.'_record:'.$orderId, $key, $data, function (User $fresh) use ($roles, $orderId): void {
            $this->commands->authorizeRoles($fresh, $roles);
            if (! $fresh->roles()->whereIn('name', ['administrator', 'secretary'])->exists() && ! DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.work_order_id', $orderId)->where('e.user_id', $fresh->id)->where('e.is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_mechanic_releases as r')->whereColumn('r.assignment_id', 'a.id'))->exists()) {
                throw new AuthorizationException('Participación vigente requerida.');
            }
        },
            function (User $fresh) use ($orderId, $data, $kind, $table, $actorField): array {
                DB::table('workshop_work_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
                $id = (string) Str::uuid();
                $row = array_merge($data, ['id' => $id, $actorField => $fresh->id]);
                if (in_array($kind, ['material_need', 'external'], true)) {
                    $row['work_order_id'] = $orderId;
                }
                if ($kind === 'external') {
                    DB::table($table)->insert(array_merge($row, ['root_record_id' => $id, 'revision' => 1]));
                } else {
                    $quantity = $row['quantity'];
                    unset($row['quantity']);
                    DB::select('SELECT workshop_write_'.$kind.'(?::jsonb, ?::text)', [json_encode($row, JSON_THROW_ON_ERROR), $quantity]);
                }
                $snapshot = DB::selectOne("SELECT to_jsonb(t)::text AS data FROM {$table} t WHERE id=?", [$id])->data;
                $destination = $kind === 'external' ? 'external_repair_id' : $kind.'_id';

                return ['operation' => ['work_order_id' => $orderId, 'operation_type' => $kind.'_record', $destination => $id, 'after_values' => $snapshot, 'reason' => $data['reason'], 'occurred_at' => $data['occurred_at'] ?? $data['performed_at']], 'result' => [$destination => $id]];
            });
    }
}
