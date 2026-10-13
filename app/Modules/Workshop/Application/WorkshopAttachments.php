<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use App\Modules\Workshop\Infrastructure\PrivateWorkshopFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkshopAttachments
{
    public function __construct(private readonly WorkshopCommands $commands, private readonly PrivateWorkshopFiles $files) {}

    public function reserve(User $actor, string $orderId, string $key, array $data): array
    {
        $this->ownTransaction();
        $data = Validator::make($data, [
            'category' => 'required|in:photo,document,invoice', 'extension' => 'required|in:pdf,jpg,jpeg,png',
            'mime' => 'required|in:application/pdf,image/jpeg,image/png', 'size_bytes' => 'required|integer|min:1|max:10000000',
            'checksum' => 'required|regex:/^[0-9a-f]{64}$/D', 'reason' => 'required|string',
            'external_repair_id' => 'nullable|uuid', 'previous_attachment_id' => 'nullable|uuid', 'decision_id' => 'nullable|uuid',
        ])->validate();
        $data['size_bytes'] = (int) $data['size_bytes'];
        $matches = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if ($matches[$data['extension']] !== $data['mime'] || trim($data['reason']) === '') {
            throw ValidationException::withMessages(['file' => 'Formato o motivo inválido.']);
        }
        $candidate = (string) Str::uuid();

        return $this->files->locked($candidate, fn () => $this->commands->run($actor, 'attachment_reserve:'.$orderId, $key, $data,
            fn (User $fresh) => $this->permit($fresh, $orderId),
            function (User $fresh, string $operationId) use ($orderId, $data): array {
                DB::table('workshop_work_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
                $count = DB::table('workshop_attachments')->where('work_order_id', $orderId)->count();
                $count += DB::table('workshop_operations as r')->where('r.work_order_id', $orderId)->where('r.operation_type', 'attachment_reserve')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_operations as t')->whereColumn('t.reservation_operation_id', 'r.id'))->count();
                if ($count >= (int) config('workshop.attachment_limit', 20)) {
                    throw ValidationException::withMessages(['file' => 'Límite de adjuntos alcanzado.']);
                }
                if (! empty($data['external_repair_id'])) {
                    DB::table('workshop_external_repairs')->where('id', $data['external_repair_id'])->where('work_order_id', $orderId)->firstOrFail();
                }
                if (! empty($data['previous_attachment_id'])) {
                    $this->revisionApproval($fresh, $orderId, $data);
                } elseif (! empty($data['decision_id'])) {
                    throw ValidationException::withMessages(['decision_id' => 'Decisión sin evidencia previa.']);
                }
                $result = ['reservation_id' => $operationId, 'work_order_id' => $orderId];

                return ['result' => $result, 'operation' => ['work_order_id' => $orderId, 'operation_type' => 'attachment_reserve', 'reason' => $data['reason'], 'after_values' => $data]];
            }, $candidate));
    }

    public function confirm(User $actor, string $reservationId, string $key, ?string $source = null): array
    {
        $this->ownTransaction();

        return $this->files->locked($reservationId, function () use ($actor, $reservationId, $key, $source): array {
            $reservation = $this->reservation($reservationId);
            $this->permit($actor, $reservation->work_order_id);
            if ((int) $reservation->actor_id !== (int) $actor->id) {
                throw new AuthorizationException('Confirmación reservada al cargador original.');
            }
            $expected = json_decode($reservation->after_values, true, flags: JSON_THROW_ON_ERROR);
            $storageKey = $reservationId.'.'.$expected['extension'];
            $terminal = DB::table('workshop_operations')->where('reservation_operation_id', $reservationId)->first();
            if ($terminal === null) {
                // Recovery accepts only a server-derived private path, never a client path.
                $this->files->write($source ?? $this->files->path($storageKey), $storageKey, $expected);
            }

            return $this->commands->run($actor, 'attachment_confirm:'.$reservationId, $key, ['reservation_id' => $reservationId],
                fn (User $fresh) => $this->permit($fresh, $reservation->work_order_id),
                function (User $fresh, string $operationId) use ($reservation, $reservationId, $expected, $storageKey): array {
                    DB::table('workshop_work_orders')->where('id', $reservation->work_order_id)->lockForUpdate()->firstOrFail();
                    $terminal = DB::table('workshop_operations')->where('reservation_operation_id', $reservationId)->first();
                    if ($terminal !== null) {
                        throw ValidationException::withMessages(['reservation_id' => 'Reserva ya finalizada; consulte el recibo original.']);
                    }
                    if ($reservation->actor_id !== $fresh->id) {
                        throw new AuthorizationException('Confirmación reservada al cargador original.');
                    }
                    $this->files->verify($this->files->path($storageKey), $expected);
                    $previous = ! empty($expected['previous_attachment_id']) ? $this->revisionApproval($fresh, $reservation->work_order_id, $expected) : null;
                    $id = (string) Str::uuid();
                    if ($previous !== null) {
                        $planned = DB::selectOne("SELECT q.content->>'planned_record_id' AS id FROM workshop_authorization_requests q JOIN workshop_authorization_decisions d ON d.request_id=q.id WHERE d.id=?", [$expected['decision_id']]);
                        $id = $planned?->id ?? $id;
                    }
                    DB::table('workshop_attachments')->insert([
                        'id' => $id, 'work_order_id' => $reservation->work_order_id, 'category' => $expected['category'],
                        'external_repair_id' => $expected['external_repair_id'] ?? null, 'storage_key' => $storageKey,
                        'mime' => $expected['mime'], 'extension' => $expected['extension'], 'size_bytes' => $expected['size_bytes'],
                        'checksum' => $expected['checksum'], 'uploaded_by_id' => $fresh->id,
                        'root_record_id' => $previous?->root_record_id ?? $id, 'revision' => $previous === null ? 1 : $previous->revision + 1, 'previous_attachment_id' => $previous?->id, 'reservation_operation_id' => $reservationId,
                    ]);

                    if ($previous !== null) {
                        DB::table('workshop_authorization_applications')->insert(['id' => (string) Str::uuid(), 'decision_id' => $expected['decision_id'], 'operation_id' => $operationId, 'applied_by_id' => $fresh->id]);
                    }

                    return ['result' => ['attachment_id' => $id, 'reservation_id' => $reservationId], 'operation' => ['work_order_id' => $reservation->work_order_id, 'operation_type' => 'attachment_confirm', 'reservation_operation_id' => $reservationId, 'attachment_id' => $id, 'reason' => $expected['reason']]];
                });
        });
    }

    public function release(User $actor, string $reservationId, string $key, string $reason): array
    {
        $this->ownTransaction();
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Motivo requerido.']);
        }

        return $this->files->locked($reservationId, function () use ($actor, $reservationId, $key, $reason): array {
            $reservation = $this->reservation($reservationId);
            $result = $this->commands->run($actor, 'attachment_release:'.$reservationId, $key, ['reservation_id' => $reservationId, 'reason' => $reason],
                function (User $fresh) use ($reservation): void {
                    $this->permit($fresh, $reservation->work_order_id);
                    if ($fresh->id !== $reservation->actor_id) {
                        $this->commands->authorizeRoles($fresh, ['administrator']);
                    }
                }, function () use ($reservation, $reservationId, $reason): array {
                    DB::table('workshop_work_orders')->where('id', $reservation->work_order_id)->lockForUpdate()->firstOrFail();
                    if (DB::table('workshop_operations')->where('reservation_operation_id', $reservationId)->exists()) {
                        throw ValidationException::withMessages(['reservation_id' => 'Reserva ya finalizada.']);
                    }

                    return ['result' => ['reservation_id' => $reservationId, 'released' => true], 'operation' => ['work_order_id' => $reservation->work_order_id, 'operation_type' => 'attachment_release', 'reservation_operation_id' => $reservationId, 'reason' => $reason]];
                });
            $expected = json_decode($reservation->after_values, true, flags: JSON_THROW_ON_ERROR);
            $this->files->remove($reservationId.'.'.$expected['extension']);

            return $result;
        });
    }

    public function listForOrder(User $actor, string $orderId, int $page = 1): LengthAwarePaginator
    {
        $this->permit($actor, $orderId, false);

        $query = DB::table('workshop_attachments')->where('work_order_id', $orderId);
        if (! $actor->roles()->whereIn('name', ['administrator', 'secretary'])->exists()) {
            $query->where('category', '<>', 'invoice')->whereNull('external_repair_id')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_cost_records as c')->whereColumn('c.source_attachment_id', 'workshop_attachments.id'));
        }

        return $query
            ->select(['id', 'work_order_id', 'category', 'external_repair_id', 'mime', 'extension', 'size_bytes', 'checksum', 'uploaded_by_id', 'registered_at', 'root_record_id', 'previous_attachment_id', 'revision', 'reservation_operation_id'])
            ->orderBy('registered_at')->orderBy('id')->paginate(25, ['*'], 'page', max(1, $page));
    }

    public function receipt(User $actor, string $reservationId): array
    {
        $reservation = $this->reservation($reservationId);
        $this->permit($actor, $reservation->work_order_id);
        $current = User::query()->findOrFail($actor->id);
        if ($current->id !== $reservation->actor_id) {
            $this->commands->authorizeRoles($current, ['administrator']);
        }
        $terminal = DB::table('workshop_operations')->where('reservation_operation_id', $reservationId)->first();

        return ['reservation_id' => $reservationId, 'work_order_id' => $reservation->work_order_id,
            'status' => $terminal?->operation_type ?? 'attachment_reserved',
            'result' => json_decode($terminal?->confirmed_result ?? $reservation->confirmed_result, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function download(User $actor, string $attachmentId): BinaryFileResponse
    {
        $attachment = DB::table('workshop_attachments')->where('id', $attachmentId)->firstOrFail();
        $this->permit($actor, $attachment->work_order_id, false);
        if (! $actor->roles()->whereIn('name', ['administrator', 'secretary'])->exists() && ($attachment->category === 'invoice' || $attachment->external_repair_id !== null || DB::table('workshop_cost_records')->where('source_attachment_id', $attachmentId)->exists())) {
            throw new AuthorizationException('Documento económico no autorizado para mecánico.');
        }
        $path = $this->files->path($attachment->storage_key);
        $this->files->verify($path, (array) $attachment);

        return response()->download($path, $attachment->id.'.'.$attachment->extension, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $attachment->mime])->setPrivate();
    }

    private function revisionApproval(User $actor, string $orderId, array $data): object
    {
        $previous = DB::table('workshop_attachments')->where('id', $data['previous_attachment_id'])->where('work_order_id', $orderId)->lockForUpdate()->firstOrFail();
        if (DB::table('workshop_attachments')->where('previous_attachment_id', $previous->id)->exists()) {
            throw ValidationException::withMessages(['previous_attachment_id' => 'La evidencia ya tiene sucesor.']);
        }
        $decision = DB::table('workshop_authorization_decisions as d')->join('workshop_authorization_requests as q', 'q.id', '=', 'd.request_id')
            ->where('d.id', $data['decision_id'] ?? null)->where('d.decision', 'approved')->where('q.type', 'rectification')->where('q.work_order_id', $orderId)->select(['q.content', 'q.target_version', 'd.id'])->first();
        if ($decision === null || DB::table('workshop_authorization_applications')->where('decision_id', $decision->id)->exists()) {
            throw ValidationException::withMessages(['decision_id' => 'Rectificación requiere decisión vigente no aplicada.']);
        }
        $content = json_decode($decision->content, true, flags: JSON_THROW_ON_ERROR);
        $after = array_intersect_key($data, array_flip(['category', 'external_repair_id', 'mime', 'extension', 'size_bytes', 'checksum']));
        $after['external_repair_id'] ??= null;
        $before = json_decode(DB::selectOne('SELECT to_jsonb(a)::text AS snapshot FROM workshop_attachments a WHERE id=?', [$previous->id])->snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (($content['fact_table'] ?? null) !== 'workshop_attachments' || ($content['fact_id'] ?? null) !== $previous->id || (int) ($content['executor_id'] ?? 0) !== (int) $actor->id || (int) $decision->target_version !== (int) DB::table('workshop_work_orders')->where('id', $orderId)->value('version') || $this->commands->fingerprint($content['before'] ?? []) !== $this->commands->fingerprint($before) || $this->commands->fingerprint($content['after'] ?? []) !== $this->commands->fingerprint($after)) {
            throw ValidationException::withMessages(['decision_id' => 'Contenido o ejecutor distinto de la autorización.']);
        }

        return $previous;
    }

    private function ownTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Los archivos requieren commit real dentro de su lock privado.');
        }
    }

    private function reservation(string $id): object
    {
        return DB::table('workshop_operations')->where('id', $id)->where('operation_type', 'attachment_reserve')->firstOrFail();
    }

    private function permit(User $actor, string $orderId, bool $write = true): void
    {
        $current = User::query()->findOrFail($actor->id);
        $this->commands->authorizeRoles($current, ['administrator', 'secretary', 'mechanic']);
        if ($current->roles()->whereIn('name', ['administrator', 'secretary'])->exists()) {
            return;
        }
        $query = DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.work_order_id', $orderId)->where('e.user_id', $current->id);
        if ($write) {
            $query->where('e.is_active', true)->where('a.starts_at', '<=', now())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_mechanic_releases as r')->whereColumn('r.assignment_id', 'a.id')->where('r.ends_at', '<=', now()));
        }
        if (! $query->exists()) {
            throw new AuthorizationException('Orden sin participación autorizada.');
        }
    }
}
