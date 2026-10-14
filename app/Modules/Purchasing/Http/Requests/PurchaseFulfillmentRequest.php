<?php

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseFulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(WorkshopAccess::class)->office($this->user());

        return true;
    }

    public function rules(): array
    {
        $receipt = $this->route('id') !== null;
        $key = $receipt ? 'purchase_order_item_id' : 'purchase_request_item_id';
        $rules = ['request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:5000'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'array:'.$key.',quantity'], 'items.*.'.$key => ['required', 'uuid', 'distinct', 'exists:'.($receipt ? 'purchase_order_items' : 'purchase_request_items').',id'], 'items.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/', function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && preg_match('/^0(?:\.0+)?$/', $value)) {
                $fail('La cantidad debe ser mayor que cero.');
            }
        }]];

        return $rules + ($receipt ? ['received_at' => ['required', 'date']] : ['purchase_request_id' => ['required', 'uuid', 'exists:purchase_requests,id']]);
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            foreach (array_diff(array_keys($this->all()), ['request_key', 'reason', 'items', $this->route('id') !== null ? 'received_at' : 'purchase_request_id', '_token']) as $field) {
                $v->errors()->add($field, 'Campo no permitido.');
            }
        }];
    }
}
