<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use App\Modules\Workshop\Models\WorkshopAuthorizationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates immutable server snapshots inside the existing idempotent transaction. */
final class WorkshopOrderRequests
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    public function request(User $actor, string $order, string $key, array $data): array
    {
        return $this->commands->run($actor, 'order_request:'.$order, $key, $data,
            fn (User $fresh) => $this->commands->authorizeRoles($fresh, ['administrator', 'secretary']),
            function (User $fresh) use ($order, $data): array {
                $row = DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=? FOR UPDATE', [$order]);
                if ($row === null) {
                    abort(404);
                }
                $before = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
                if ($before['version'] !== $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Versión obsoleta.']);
                }
                $after = $before;
                $after['version']++;
                if ($data['type'] === 'cancellation') {
                    $after['status'] = 'cancelled';
                } elseif ($data['type'] === 'reopening') {
                    $diagnosed = DB::table('workshop_diagnoses')->where('work_order_id', $order)->exists();
                    $authorized = DB::table('workshop_authorization_applications as a')
                        ->join('workshop_authorization_decisions as d', 'd.id', '=', 'a.decision_id')
                        ->join('workshop_authorization_requests as q', 'q.id', '=', 'd.request_id')
                        ->where('q.work_order_id', $order)->whereNotNull('q.scope_id')->exists();
                    $after['status'] = $authorized ? 'authorized' : ($diagnosed ? 'pending_authorization' : 'pending_diagnosis');
                } else {
                    $after = array_replace($after, array_intersect_key($data, array_flip(['description', 'priority'])));
                }
                $content = ['before' => $before, 'after' => $after];
                $request = WorkshopAuthorizationRequest::create(['work_order_id' => $order, 'type' => $data['type'],
                    'content' => $content, 'target_version' => $before['version'], 'requested_by_id' => $fresh->id,
                    'previous_request_id' => $data['previous_request_id'] ?? null, 'reason' => $data['reason']]);

                return ['operation' => ['work_order_id' => $order, 'operation_type' => 'authorization_request',
                    'authorization_request_id' => $request->id, 'reason' => $data['reason'], 'after_values' => $content],
                    'result' => ['request_id' => $request->id]];
            });
    }
}
