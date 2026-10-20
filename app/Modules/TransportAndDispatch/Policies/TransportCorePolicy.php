<?php

namespace App\Modules\TransportAndDispatch\Policies;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Application\ReadAuthorizationContext;
use App\Modules\TransportAndDispatch\Models\Trip;

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

    public function report(User $user, Trip $trip): bool
    {
        if ($this->manage($user)) {
            return true;
        }

        return $this->hasRole($user, ['driver']) && $trip->driverAssignments()->current()
            ->whereRaw('starts_at <= clock_timestamp()')
            ->whereHas('employee', fn ($query) => $query->where('user_id', $user->id)->where('is_active', true))->exists();
    }

    private function hasRole(User $user, array $roles): bool
    {
        if ($context = ReadAuthorizationContext::forActor($user)) {
            return $context->hasAnyRole($roles);
        }

        return User::query()->whereKey($user->id)->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->exists();
    }
}
