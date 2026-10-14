<?php

namespace App\Modules\BillingAndPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class BillingWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->can($this->routeIs('billing.amounts.decide') ? 'approve-trip-amount' : 'manage-billing');
    }

    public function rules(): array
    {
        $rules = ['request_key' => 'required|uuid', 'reason' => 'required|string|max:2000'];
        $money = ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', function (string $attribute, mixed $value, \Closure $fail): void {
            if ($this->routeIs('billing.payments.store') && is_string($value) && preg_match('/^0(?:\.0+)?$/D', $value)) {
                $fail('El importe debe ser mayor que cero.');
            }
        }];
        if ($this->routeIs('billing.amounts.store')) {
            return $rules + ['amount' => $money, 'currency' => ['required', Rule::in(['CRC', 'USD'])], 'supersedes_id' => 'nullable|uuid|exists:billing_trip_amounts,id'];
        }
        if ($this->routeIs('billing.amounts.decide')) {
            return $rules + ['decision' => ['required', Rule::in(['approved', 'rejected'])]];
        }
        if ($this->routeIs('billing.invoices.store')) {
            return $rules + ['customer_id' => 'required|uuid|exists:customers,id', 'items' => 'required|array|min:1|max:100', 'items.*' => 'required|array:trip_id,price_proposal_id,description', 'items.*.trip_id' => 'required|uuid|distinct|exists:trips,id', 'items.*.price_proposal_id' => 'required|uuid|distinct|exists:billing_trip_amounts,id', 'items.*.description' => 'required|string|max:2000'];
        }

        return $rules + ['amount' => $money, 'currency' => ['required', Rule::in(['CRC', 'USD'])], 'paid_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'], 'method' => 'nullable|string|max:255'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_merge(array_keys($this->rules()), ['_token'])) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
        }];
    }
}
