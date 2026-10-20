<?php

namespace App\Modules\AccessAndPersonnel\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Only the active-account middleware may create this per-request read snapshot. */
final class ReadAuthorizationContext
{
    private ?array $roles = null;

    public function __construct(public readonly User $actor) {}

    public static function forActor(User $actor): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }
        $request = app('request');
        $context = $request->attributes->get(self::class);
        if (! $request->isMethod('GET') || ! $context instanceof self
            || (string) $context->actor->id !== (string) $actor->id) {
            return null;
        }
        if (DB::transactionLevel() !== 0) {
            // Never reuse a read snapshot after a transactional authorization.
            $request->attributes->remove(self::class);

            return null;
        }

        return $context;
    }

    public function hasAnyRole(array $roles): bool
    {
        $this->roles ??= $this->actor->roles()->pluck('name')->all();

        return $this->actor->is_active && array_intersect($roles, $this->roles) !== [];
    }
}
