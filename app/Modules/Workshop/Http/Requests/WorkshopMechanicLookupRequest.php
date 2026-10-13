<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Application\WorkshopAccess;

class WorkshopMechanicLookupRequest extends WorkshopInput
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        app(WorkshopAccess::class)->office($this->user());

        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
