<?php

namespace App\Modules\AccessAndPersonnel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-personnel') ?? false;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'is_active' => [$presence, 'required', 'boolean'],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->ignore($this->route('id'))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['name', 'is_active', 'user_id', '_token']) as $field) {
                $validator->errors()->add($field, 'Este campo no se puede modificar mediante la gestión de empleados.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el límite permitido.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
            'integer' => 'El campo :attribute debe ser entero.',
            'user_id.exists' => 'La cuenta de usuario seleccionada no existe.',
            'user_id.unique' => 'La cuenta de usuario ya está vinculada a otro empleado.',
        ];
    }
}
