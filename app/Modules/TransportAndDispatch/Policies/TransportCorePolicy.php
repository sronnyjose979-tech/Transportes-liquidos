<?php

namespace App\Modules\TransportAndDispatch\Policies;

use App\Models\User;

class TransportCorePolicy
{
    public function manage(User $user): bool
    {
        return $this->hasRole($user, ['administrator', 'secretary']);
    }

    public function decide(User $user): bool
    {
        return $this->hasRole($user, ['administrator']);
    }

    private function hasRole(User $user, array $roles): bool
    {
        return User::query()->whereKey($user->id)->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->exists();
    }
}
