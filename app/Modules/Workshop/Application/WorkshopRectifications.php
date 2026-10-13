<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Builds a precise, server-side historical correction for administrative approval. */
final class WorkshopRectifications
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    public function request(User $actor, string $key, string $table, string $factId, int $version, array $changes, int $executorId, string $reason): array
    {
        $payload = compact('table', 'factId', 'version', 'changes', 'executorId', 'reason');

        return $this->commands->run($actor, 'fact_rectification_request:'.$table.':'.$factId, $key, $payload,
            fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator']),
            function (User $fresh) use ($table, $factId, $version, $changes, $executorId, $reason): array {
                $context = json_decode(DB::selectOne('SELECT workshop_fact_context(?::text,?::uuid)::text AS data', [$table, $factId])->data, true, flags: JSON_THROW_ON_ERROR);
                $order = DB::table('workshop_work_orders')->where('id', $context['work_order_id'])->lockForUpdate()->firstOrFail();
                if ($order->version !== $version || trim($reason) === '') {
                    throw ValidationException::withMessages(['version' => 'Versión vigente y motivo requeridos.']);
                }
                $before = $context['fact'];
                $values = json_decode(DB::selectOne('SELECT workshop_fact_values(?::text,?::jsonb)::text AS data', [$table, json_encode($before, JSON_THROW_ON_ERROR)])->data, true, flags: JSON_THROW_ON_ERROR);
                if (array_diff(array_keys($changes), array_keys($values)) !== []) {
                    throw ValidationException::withMessages(['changes' => 'Solo campos de negocio del hecho pueden rectificarse.']);
                }
                $after = array_replace($values, $changes);
                foreach (['quantity', 'amount'] as $field) {
                    if (isset($changes[$field]) && ! is_string($changes[$field])) {
                        throw ValidationException::withMessages([$field => 'Decimal como texto exacto requerido.']);
                    }
                }
                $plannedId = (string) Str::uuid();
                $content = DB::selectOne("SELECT jsonb_build_object('fact_table',?::text,'fact_id',?::text,'before',workshop_fact_context(?::text,?::uuid)->'fact','after',?::jsonb,'executor_id',?::bigint,'planned_record_id',?::uuid)::text AS data", [$table, $factId, $table, $factId, json_encode($after, JSON_THROW_ON_ERROR), $executorId, $plannedId])->data;
                $requestId = (string) Str::uuid();
                DB::table('workshop_authorization_requests')->insert(['id' => $requestId, 'work_order_id' => $order->id, 'type' => 'rectification', 'target_version' => $version, 'content' => $content, 'requested_by_id' => $fresh->id, 'reason' => $reason]);

                return ['operation' => ['work_order_id' => $order->id, 'operation_type' => 'authorization_request', 'authorization_request_id' => $requestId, 'reason' => $reason, 'after_values' => $content], 'result' => ['request_id' => $requestId, 'planned_record_id' => $plannedId]];
            });
    }
}
