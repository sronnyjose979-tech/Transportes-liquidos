<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class WorkshopInput extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        app(WorkshopAccess::class)->module($this->user());

        return true;
    }

    public static function instant(): array
    {
        return ['required', 'string', 'date', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/'];
    }

    public static function reason(): array
    {
        return ['required', 'string', 'max:5000', 'regex:/\S/u'];
    }

    public static function decimal(bool $cost = false): array
    {
        return ['required', 'string', 'max:4096', function (string $attribute, mixed $value, \Closure $fail) use ($cost): void {
            if (! is_string($value) || ! preg_match('/\A[+-]?[0-9]+(?:\.[0-9]{1,6})?\z/', $value)) {
                $fail('Decimal como texto ASCII exacto, máximo seis decimales.');

                return;
            }
            $negative = str_starts_with($value, '-');
            $unsigned = ltrim($value, '+-');
            [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
            $integer = ltrim($integer, '0');
            $zero = $integer === '' && trim($fraction, '0') === '';
            if (($negative && ! $zero) || (! $cost && $zero) || strlen($integer) > ($cost ? 14 : 12)) {
                $fail('Valor decimal fuera del rango permitido.');
            }
        }];
    }

    public static function key(): array
    {
        return ['required', 'uuid'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = array_filter(array_keys($this->rules()), fn (string $field) => ! str_contains($field, '.'));
            foreach (array_diff(array_keys($this->all()), array_merge($fields, ['_token'])) as $field) {
                $validator->errors()->add($field, 'Campo no admitido.');
            }
        });
    }

    public function commandData(): array
    {
        return $this->safe()->except(['request_key']);
    }
}
