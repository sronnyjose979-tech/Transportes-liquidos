<?php

namespace App\Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('id') !== null ? 'decide-purchase-request' : 'manage-purchase-requests') ?? false;
    }

    public function rules(): array
    {
        $rules = ['request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:5000']];
        if ($this->route('id') !== null) {
            return $rules + ['decision' => ['required', 'in:approved,rejected']];
        }

        return $rules + [
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:spare_part_id,quantity,workshop_material_need_id'],
            'items.*.spare_part_id' => ['required', 'uuid', 'exists:spare_parts,id'],
            'items.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && preg_match('/^0(?:\.0+)?$/', $value)) {
                    $fail('La cantidad debe ser mayor que cero.');
                }
            }],
            'items.*.workshop_material_need_id' => ['nullable', 'uuid', 'exists:workshop_material_needs,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = $this->route('id') !== null ? ['request_key', 'reason', 'decision', '_token'] : ['request_key', 'reason', 'supplier_id', 'items', '_token'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
        }];
    }
}
