<?php

namespace App\Modules\AccessAndPersonnel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'login' => $this->login, 'name' => $this->name, 'email' => $this->email, 'is_active' => $this->is_active,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()),
            'employee_id' => $this->whenLoaded('employee', fn () => $this->employee?->id),
        ];
    }
}
