<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;
use Illuminate\Validation\Rule;

class WorkshopProcessRequest extends WorkshopInput
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $roles = match ($this->route('action')) {
            'close','historical','resolve','exit-request','decide','scope-apply','order-apply','exit' => ['administrator'],
            'order-request','admit','impediment' => ['administrator', 'secretary'],
            'activity','time','finish' => ['administrator', 'mechanic'],
            default => ['administrator', 'secretary', 'mechanic'],
        };
        app(WorkshopAccess::class)->roles($this->user(), $roles);

        return true;
    }

    public function rules(): array
    {
        $uuid = ['sometimes', 'nullable', 'uuid'];
        $base = ['request_key' => ['required', 'uuid']];
        $reason = ['reason' => $this->reason()];
        $at = ['occurred_at' => $this->instant()];

        return $base + match ($this->route('action')) {
            'scope' => $reason + [
                'diagnosis_id' => ['required', 'uuid'], 'description' => ['required', 'string', 'max:10000'],
                'scope_kind' => ['sometimes', Rule::in(['initial', 'extension', 'revision'])],
                'previous_scope_id' => ($this->input('scope_kind', 'initial') === 'revision' ? ['required', 'uuid'] : ['prohibited']), 'previous_request_id' => $uuid,
                'tasks' => ['required', 'array', 'min:1', 'max:100'],
                'tasks.*' => ['required', 'array:line_number,description,supplier_id'],
                'tasks.*.line_number' => ['required', 'integer:strict', 'min:1', 'distinct'],
                'tasks.*.description' => ['required', 'string', 'max:10000'], 'tasks.*.supplier_id' => $uuid,
                'parts' => ['sometimes', 'array', 'max:100'], 'parts.*' => ['required', 'array:line_number,task_line_number,spare_part_id,quantity'],
                'parts.*.line_number' => ['required', 'integer:strict', 'min:1', 'distinct'], 'parts.*.task_line_number' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
                'parts.*.spare_part_id' => ['required', 'uuid'], 'parts.*.quantity' => $this->decimal(),
            ],
            'decide' => $reason + ['decision' => ['required', Rule::in(['approved', 'rejected'])]],
            'scope-apply', 'order-apply', 'exit' => [],
            'order-request' => $reason + ['type' => ['required', Rule::in(['cancellation', 'reopening', 'rectification'])],
                'version' => ['required', 'integer:strict', 'min:1'], 'previous_request_id' => $uuid] + ($this->input('type') === 'rectification' ? [
                    'description' => ['sometimes', 'string', 'max:10000'], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])]] : []),
            'activity' => $reason + $at + ['task_id' => ['required', 'uuid'], 'mechanic_assignment_id' => $uuid,
                'description' => ['required', 'string', 'max:10000'], 'observations' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'previous_activity_id' => $uuid, 'decision_id' => $uuid, 'version' => ['sometimes', 'integer:strict', 'min:1']],
            'time' => $reason + $at + ['mechanic_assignment_id' => ['required', 'uuid'], 'event_type' => ['required', Rule::in(['start', 'pause', 'resume', 'finish'])],
                'previous_event_id' => $uuid, 'decision_id' => $uuid, 'version' => ['sometimes', 'integer:strict', 'min:1']],
            'finish', 'close' => $reason + $at + ['version' => ['required', 'integer:strict', 'min:1'], 'mechanic_assignment_id' => $uuid] + ($this->route('action') === 'close' ? [
                'review' => ['required', 'array', 'min:1', 'max:20'], 'review.*' => ['required', 'string', 'max:10000'],
            ] : []),
            'admit' => $reason + ['kind' => ['required', Rule::in(['corrective', 'preventive'])], 'admitted_at' => $this->instant()],
            'historical', 'resolve' => $reason,
            'impediment' => $reason + $at + ['admission_id' => $uuid, 'work_order_id' => $uuid, 'effect' => ['required', Rule::in(['maintenance', 'out_of_service'])], 'description' => ['required', 'string', 'max:10000']],
            'exit-request' => $reason + ['resolve_ids' => ['present', 'array', 'max:100'], 'resolve_ids.*' => ['required', 'uuid', 'distinct'], 'exited_at' => $this->instant(), 'previous_request_id' => $uuid],
            'link-admission' => $reason + ['admission_id' => ['required', 'uuid']],
            default => [],
        };
    }
}
