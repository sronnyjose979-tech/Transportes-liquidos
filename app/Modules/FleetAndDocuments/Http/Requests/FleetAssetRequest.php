<?php

namespace App\Modules\FleetAndDocuments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FleetAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fleet') ?? false;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $tanker = $this->routeIs('fleet.tankers.*');
        $rules = [
            'code' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255', Rule::unique('fleet_assets', 'code')->ignore($this->route('id'))],
        ];
        if ($tanker) {
            if ($creating) {
                $rules['has_physical_partitions'] = ['required', 'boolean'];
            }
            $rules['compartments'] = [$creating ? 'required' : 'sometimes', 'array', 'list', 'min:1'];
            $rules['compartments.*'] = ['required', 'array:'.($creating ? 'code,capacity_liters,is_active' : 'id,code,capacity_liters')];
            $rules['compartments.*.code'] = [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'];
            // Reject extra precision rather than allowing PostgreSQL to round input.
            $rules['compartments.*.capacity_liters'] = [$creating ? 'required' : 'sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'];
            if ($creating) {
                $rules['compartments.*.is_active'] = ['required', 'boolean'];
            } else {
                $rules['compartments.*.id'] = ['required', 'uuid', 'distinct'];
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $creating = $this->isMethod('POST');
            $allowed = ['code', '_token'];
            if ($this->routeIs('fleet.tankers.*')) {
                $allowed[] = 'compartments';
                if ($creating) {
                    $allowed[] = 'has_physical_partitions';
                    if ($this->has('has_physical_partitions') && ! $this->boolean('has_physical_partitions')
                        && is_array($this->input('compartments')) && count($this->input('compartments')) !== 1) {
                        $validator->errors()->add('compartments', 'Una cisterna sin divisiones físicas debe tener exactamente un compartimento lógico.');
                    }
                }
            }
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Este campo no se puede modificar mediante la administración de flota.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el límite permitido.',
            'min' => 'El campo :attribute debe contener al menos un elemento.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
            'array' => 'El campo :attribute contiene una estructura no permitida.',
            'list' => 'El campo :attribute debe ser una lista.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'decimal' => 'El campo :attribute admite como máximo dos decimales.',
            'gt' => 'El campo :attribute debe ser mayor que cero.',
            'uuid' => 'El campo :attribute debe ser un UUID válido.',
            'distinct' => 'El campo :attribute no debe repetirse.',
            'code.unique' => 'El código del activo ya está en uso.',
        ];
    }
}
