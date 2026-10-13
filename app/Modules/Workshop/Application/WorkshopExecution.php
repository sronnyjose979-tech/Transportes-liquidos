<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use App\Modules\Workshop\Models\WorkshopActivity;
use App\Modules\Workshop\Models\WorkshopAuthorizationApplication;
use App\Modules\Workshop\Models\WorkshopTimeEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkshopExecution
{
    public function __construct(private readonly WorkshopCommands $commands) {}

    private function authorize(User $actor, string $order, ?string $assignment, bool $closing = false): void
    {
        $this->commands->authorizeRoles($actor, $closing ? ['administrator'] : ['administrator', 'mechanic']);
        if (! $actor->roles()->where('name', 'administrator')->exists()) {
            if (! DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')
                ->where('a.id', $assignment)->where('a.work_order_id', $order)->where('e.user_id', $actor->id)->where('e.is_active', true)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_mechanic_releases as r')->whereColumn('r.assignment_id', 'a.id'))->exists()) {
                throw new AuthorizationException('Participación vigente propia requerida.');
            }
        }
    }

    public function recordActivity(User $actor, string $key, array $data): array
    {
        $order = DB::table('workshop_scope_tasks as t')->join('workshop_scopes as s', 's.id', '=', 't.scope_id')->where('t.id', $data['task_id'])->value('s.work_order_id');
        if ($order === null) {
            throw ValidationException::withMessages(['task_id' => 'Tarea inexistente.']);
        }

        return $this->record($actor, $order, $key, $data, WorkshopActivity::class, 'activity', 'previous_activity_id');
    }

    public function recordTime(User $actor, string $key, array $data): array
    {
        return $this->record($actor, $data['work_order_id'], $key, $data, WorkshopTimeEvent::class, 'time', 'previous_event_id');
    }

    private function record(User $actor, string $order, string $key, array $data, string $model, string $kind, string $previousField): array
    {
        $type = $kind.(isset($data[$previousField]) ? '_revise' : '_record');

        return $this->commands->run($actor, $type.':'.$order, $key, $data,
            fn (User $fresh) => $this->authorize($fresh, $order, $data['mechanic_assignment_id'] ?? null),
            function (User $fresh, string $operationId) use ($order, $data, $model, $kind, $type, $previousField): array {
                $before = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=? FOR UPDATE', [$order])->data, true);
                $previous = isset($data[$previousField]) ? $model::query()->lockForUpdate()->findOrFail($data[$previousField]) : null;
                if (in_array($before['status'], ['finished', 'closed', 'cancelled'], true) && ($previous === null || ! isset($data['decision_id']))) {
                    throw ValidationException::withMessages(['decision_id' => 'Rectificación histórica requiere autorización administrativa explícita.']);
                }
                $id = (string) Str::uuid();
                if (isset($data['decision_id'])) {
                    $planned = DB::selectOne("SELECT q.content->>'planned_record_id' AS id FROM workshop_authorization_requests q JOIN workshop_authorization_decisions d ON d.request_id=q.id WHERE d.id=?", [$data['decision_id']]);
                    $id = $planned?->id ?? $id;
                }
                $recordData = array_intersect_key($data, array_flip((new $model)->getFillable()));
                unset($recordData['version']);
                $record = $model::forceCreate(array_merge($recordData, ['id' => $id, 'actor_id' => $fresh->id, 'root_record_id' => $previous?->root_record_id ?? $id, 'revision' => $previous ? $previous->revision + 1 : 1]));
                $field = $kind === 'activity' ? 'activity_id' : 'time_event_id';

                $operation = ['work_order_id' => $order, 'operation_type' => $type, $field => $id, 'reason' => $data['reason'], 'occurred_at' => $record->occurred_at->toIso8601String(), 'after_values' => $record->toArray()];
                if ($previous === null && $before['status'] === 'authorized') {
                    if (! isset($data['version']) || $data['version'] !== $before['version']) {
                        throw ValidationException::withMessages(['version' => 'Inicio requiere versión vigente.']);
                    }
                    DB::table('workshop_work_orders')->where('id', $order)->update(['status' => 'in_repair', 'version' => $before['version'] + 1]);
                    $after = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$order])->data, true);
                    $operation = array_merge($operation, ['from_version' => $before['version'], 'to_version' => $after['version'], 'before_values' => $before, 'after_values' => $after]);
                }

                if (isset($data['decision_id'])) {
                    if ($previous === null) {
                        throw ValidationException::withMessages(['decision_id' => 'Autorización de rectificación exige predecesor.']);
                    }
                    WorkshopAuthorizationApplication::create(['decision_id' => $data['decision_id'], 'operation_id' => $operationId, 'applied_by_id' => $fresh->id]);
                }

                return ['result' => [$field => $id], 'operation' => $operation];
            });
    }

    /** Finishing the order does not invent participant finish events or release the asset. */
    public function transition(User $actor, string $order, string $key, array $data): array
    {
        $target = $data['status'];
        $types = ['finished' => 'order_finish', 'closed' => 'order_close'];
        if (! isset($types[$target])) {
            throw ValidationException::withMessages(['status' => 'Transición de ejecución desconocida.']);
        }
        if ($target === 'closed' && (! isset($data['review']) || ! is_array($data['review']) || $data['review'] === [])) {
            throw ValidationException::withMessages(['review' => 'Revisión administrativa de trabajos, repuestos, tiempos y pendientes requerida.']);
        }

        return $this->commands->run($actor, $types[$target].':'.$order, $key, $data,
            fn (User $fresh) => $this->authorize($fresh, $order, $data['mechanic_assignment_id'] ?? null, $target === 'closed'),
            function (User $fresh) use ($order, $data, $target, $types): array {
                $before = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=? FOR UPDATE', [$order])->data, true);
                if ($before['version'] !== $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Versión obsoleta.']);
                }
                $source = ['in_repair' => 'authorized', 'finished' => 'in_repair', 'closed' => 'finished'][$target];
                if ($before['status'] !== $source) {
                    throw ValidationException::withMessages(['status' => 'Estado origen incompatible.']);
                }
                DB::table('workshop_work_orders')->where('id', $order)->update(['status' => $target, 'version' => $before['version'] + 1]);
                $after = json_decode(DB::selectOne('SELECT to_jsonb(w)::text AS data FROM workshop_work_orders w WHERE id=?', [$order])->data, true);
                $result = ['work_order_id' => $order, 'status' => $target, 'version' => $after['version']];
                if ($target === 'closed') {
                    $result['review'] = $data['review'];
                }

                return ['result' => $result, 'operation' => ['work_order_id' => $order, 'operation_type' => $types[$target], 'from_version' => $before['version'], 'to_version' => $after['version'], 'before_values' => $before, 'after_values' => $after, 'reason' => $data['reason'], 'occurred_at' => $data['occurred_at']]];
            });
    }

    public function times(User $actor, string $order): array
    {
        $actor = User::query()->findOrFail($actor->id);
        $this->commands->authorizeRoles($actor, ['administrator', 'secretary', 'mechanic']);
        if (! $actor->roles()->whereIn('name', ['administrator', 'secretary'])->exists() && ! DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.work_order_id', $order)->where('e.user_id', $actor->id)->exists()) {
            throw new AuthorizationException('Orden ajena.');
        }
        $events = WorkshopTimeEvent::query()->where('work_order_id', $order)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_time_events as r')->whereColumn('r.previous_event_id', 'workshop_time_events.id'))->orderBy('occurred_at')->get();
        $participants = [];
        foreach ($events as $event) {
            $id = $event->mechanic_assignment_id;
            $participants[$id] ??= ['confirmed_seconds' => 0, 'open_since' => null];
            if (in_array($event->event_type, ['start', 'resume'], true)) {
                $participants[$id]['open_since'] = $event->occurred_at->format('Y-m-d\TH:i:s.uP');
                $opened[$id] = (int) $event->occurred_at->format('U') * 1000000 + (int) $event->occurred_at->format('u');
            } else {
                $micros[$id] = ($micros[$id] ?? 0) + (int) $event->occurred_at->format('U') * 1000000 + (int) $event->occurred_at->format('u') - $opened[$id];
                $participants[$id]['open_since'] = null;
            }
        }

        // Keep microsecond precision without binary floating point.
        $seconds = static fn (int $value): int|string => $value % 1000000 === 0
            ? intdiv($value, 1000000)
            : intdiv($value, 1000000).'.'.str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
        foreach ($participants as $id => &$participant) {
            $participant['confirmed_seconds'] = $seconds($micros[$id] ?? 0);
        }
        unset($participant);

        return ['participants' => $participants, 'person_seconds' => $seconds(array_sum($micros ?? []))];
    }
}
