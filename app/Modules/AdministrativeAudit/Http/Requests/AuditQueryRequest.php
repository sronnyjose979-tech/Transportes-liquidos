<?php

namespace App\Modules\AdministrativeAudit\Http\Requests;

use App\Modules\AdministrativeAudit\Application\AdministrativeAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AuditQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-administrative-audit') ?? false;
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : []),
            'actor_id' => 'sometimes|integer|min:1|exists:users,id',
            'module' => ['sometimes', Rule::in(AdministrativeAudit::MODULES)],
            'operation' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/'],
        ];
    }
}
