<?php

namespace App\Modules\TransportAndDispatch\Http\Requests;

use Illuminate\Validation\Rule;

class AuthorizationWriteRequest extends TransportInput
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->routeIs('transport.authorizations.decide') ? 'decide-transport-authorization' : 'request-transport-authorization') ?? false;
    }

    public function rules(): array
    {
        if ($this->routeIs('transport.authorizations.decide')) {
            return ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:5000']];
        }

        return ['type' => ['required', Rule::in(['assignment_conflict', 'driver_substitution', 'tractor_substitution', 'volume_difference'])],
            'reason' => ['required', 'string', 'max:5000'], 'content' => ['required_unless:type,volume_difference', 'prohibited_if:type,volume_difference', 'array:action,resource_kind,resource_id'],
            'content.action' => ['required_with:content', Rule::in(['assign', 'substitute'])],
            'content.resource_kind' => ['required_with:content', Rule::in(['driver', 'tractor_unit', 'tanker'])],
            'content.resource_id' => ['required_with:content', 'uuid']];
    }
}
