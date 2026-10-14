<?php

namespace App\Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ExpenseWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->can($this->routeIs('expenses.decide') ? 'approve-expenses' : 'access-expenses');
    }

    public function rules(): array
    {
        $key = ['request_key' => 'required|uuid'];
        if ($this->routeIs('expenses.receipts.store')) {
            return $key + ['file' => 'required|file'];
        }
        if ($this->routeIs('expenses.decide')) {
            return $key + ['expected_revision_id' => 'required|uuid', 'decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => 'required|string|max:2000'];
        }
        $fields = ['concept' => 'required|string|max:255', 'category' => 'required|string|max:255', 'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', 'not_in:0,0.0,0.00'], 'currency' => ['required', Rule::in(['CRC', 'USD'])], 'occurred_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'], 'description' => 'nullable|string|max:4000'];
        if ($this->routeIs('expenses.correct')) {
            return $key + $fields + ['supersedes_id' => 'required|uuid', 'reason' => 'required|string|max:2000'];
        }

        return $key + $fields + ['trip_id' => 'nullable|uuid|exists:trips,id'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_merge(array_keys($this->rules()), ['_token'])) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
            if ($this->routeIs('expenses.receipts.store') && ($file = $this->file('file'))) {
                $extension = strtolower($file->getClientOriginalExtension());
                $expected = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
                if ($file->getSize() < 1 || $file->getSize() > 10000000 || ! isset($expected[$extension]) || $file->getMimeType() !== ($expected[$extension] ?? null)) {
                    $validator->errors()->add('file', 'Comprobante PDF, JPG o PNG real de hasta 10.000.000 bytes.');
                }
                if (in_array($extension, ['jpg', 'png'], true) && @getimagesize($file->getPathname()) === false) {
                    $validator->errors()->add('file', 'Imagen inválida.');
                }
                if ($extension === 'pdf' && file_get_contents($file->getPathname(), false, null, 0, 5) !== '%PDF-') {
                    $validator->errors()->add('file', 'PDF inválido.');
                }
            }
        }];
    }
}
