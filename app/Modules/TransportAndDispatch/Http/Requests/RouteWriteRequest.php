<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RouteWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalogs') ?? false;
    }

    public function rules(): array
    {
        $rules = ['name' => ['required', 'string', 'max:255'],
            'origin_site_id' => ['required', 'uuid', 'exists:operation_sites,id'],
            'destination_site_id' => ['required', 'uuid', 'exists:operation_sites,id']];
        if ($this->isMethod('PATCH')) {
            $rules += ['expected' => ['required', 'array:name,origin_site_id,destination_site_id'],
                'expected.name' => ['required', 'string', 'max:255'], 'expected.origin_site_id' => ['required', 'uuid'],
                'expected.destination_site_id' => ['required', 'uuid']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['name', 'origin_site_id', 'destination_site_id', '_token'];
            if ($this->isMethod('PATCH')) {
                $allowed[] = 'expected';
            }
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Campo no permitido en la administración de rutas.');
            }
        }];
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el límite permitido.', 'uuid' => 'Selecciona un identificador válido.',
            'exists' => 'El sitio operativo seleccionado no existe.', 'array' => 'Consulta los datos actuales de la ruta antes de editar.'];
    }
}
