<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class InventoryWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->is_active && $this->user()->roles()->whereIn('name', ['administrator', 'secretary'])->exists();
    }

    public function rules(): array
    {
        $rules = ['request_key' => 'required|uuid', 'occurred_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'], 'reason' => 'required|string|max:2000'];
        if ($this->routeIs('inventory.corrections.store')) {
            $rules += ['spare_part_id' => 'required|uuid|exists:spare_parts,id', 'kind' => ['required', Rule::in(['initial', 'adjustment', 'reversal'])], 'quantity' => ['required', 'string', 'regex:/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/'], 'unit_snapshot' => 'required|string|max:255', 'reverses_movement_id' => 'nullable|uuid|exists:inventory_movements,id'];
        } elseif ($this->routeIs('inventory.decisions.store')) {
            $rules += ['decision' => ['required', Rule::in(['approved', 'rejected'])]];
        } elseif ($this->routeIs('inventory.returns.store')) {
            $rules += ['delivery_id' => 'required|uuid|exists:workshop_part_deliveries,id', 'quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/']];
        } else {
            unset($rules['reason']);
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_merge(array_keys($this->rules()), ['_token'])) !== []) {
                $validator->errors()->add('content', 'Campos no permitidos.');
            }
        }];
    }
}
