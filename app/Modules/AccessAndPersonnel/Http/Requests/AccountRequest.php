<?php

namespace App\Modules\AccessAndPersonnel\Http\Requests;

use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-personnel') ?? false;
    }

    public function rules(): array
    {
        if ($this->route('id') !== null && filter_var($this->route('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            abort(404);
        }
        if ($this->routeIs('users.activation')) {
            return ['is_active' => ['required', 'boolean']];
        }
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $id = $this->route('id');
        $rules = [
            'login' => [$required, 'required', 'string', 'max:255', Rule::unique('users', 'login')->ignore($id)],
            'name' => [$required, 'required', 'string', 'max:255'],
            'email' => [$required, 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password' => [$required, 'required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('La contraseña supera el límite seguro de bytes.');
                }
            }],
            'roles' => [$required, 'array', 'list'],
            'roles.*' => ['required', 'uuid', 'distinct', Rule::exists('roles', 'id')->whereIn('name', Role::SUPPORTED_NAMES)],
        ];
        if ($this->isMethod('POST')) {
            $rules['is_active'] = ['required', 'boolean'];
            $rules['employee'] = ['sometimes', 'array:name,is_active', 'required_array_keys:name,is_active'];
            $rules['employee.name'] = ['required_with:employee', 'string', 'max:255'];
            $rules['employee.is_active'] = ['required_with:employee', 'boolean'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['name' => 'nombre completo', 'login' => 'usuario de acceso', 'email' => 'correo electrónico',
            'password' => 'contraseña', 'password_confirmation' => 'confirmación de contraseña',
            'roles' => 'perfil de trabajo', 'employee' => 'datos de la persona',
            'employee.name' => 'nombre completo', 'employee.is_active' => 'estado del empleado'];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.',
            'required_with' => 'El campo :attribute es obligatorio.',
            'required_array_keys' => 'Completa el nombre y el estado de la persona.',
            'max' => 'El campo :attribute supera el límite permitido.', 'min' => 'El campo :attribute no alcanza el mínimo permitido.',
            'email' => 'El correo no es válido.', 'unique' => 'El valor de :attribute ya está en uso.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.', 'array' => 'El campo :attribute debe ser una lista.',
            'uuid' => 'El identificador de rol no es válido.', 'distinct' => 'No se permiten roles duplicados.',
            'list' => 'Los roles deben ser una lista.', 'exists' => 'El rol no existe o no está permitido.', 'confirmed' => 'La confirmación de contraseña no coincide.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $allowed = array_keys($this->rules());
            $allowed[] = 'password_confirmation';
            $allowed[] = '_token';
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en esta operación.');
            }
        }];
    }
}
