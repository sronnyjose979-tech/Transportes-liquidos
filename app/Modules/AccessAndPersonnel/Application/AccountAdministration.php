<?php

namespace App\Modules\AccessAndPersonnel\Application;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountAdministration
{
    public function save(User $actor, array $data, ?int $id = null): User
    {
        try {
            return DB::transaction(function () use ($actor, $data, $id) {
                // Small administrative workload: serialize account privileges and personnel writes.
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                $freshActor = User::query()->lockForUpdate()->findOrFail($actor->id);
                Gate::forUser($freshActor)->authorize('manage-personnel');
                $user = $id === null ? new User : User::query()->lockForUpdate()->findOrFail($id);
                $roles = $data['roles'] ?? null;
                $employee = $data['employee'] ?? null;
                unset($data['employee']);
                if ($employee !== null && $id !== null) {
                    throw ValidationException::withMessages(['employee' => 'El alta guiada solo está disponible al registrar una cuenta nueva.']);
                }
                if ($roles !== null && Role::query()->whereIn('id', $roles)->whereIn('name', Role::SUPPORTED_NAMES)->count() !== count($roles)) {
                    throw ValidationException::withMessages(['roles' => 'Uno de los roles no está permitido.']);
                }
                unset($data['roles']);
                if (array_key_exists('is_active', $data)) {
                    $data['is_active'] = (bool) $data['is_active'];
                }
                $wasActiveAdministrator = $user->exists && $user->is_active
                    && $user->roles()->where('name', 'administrator')->exists();
                $retainsAdministratorRole = $roles === null || Role::query()
                    ->whereIn('id', $roles)->where('name', 'administrator')->exists();
                if ($wasActiveAdministrator && (! ($data['is_active'] ?? $user->is_active) || ! $retainsAdministratorRole)
                    && ! User::query()->where('is_active', true)->whereKeyNot($user->id)
                        ->whereHas('roles', fn ($query) => $query->where('name', 'administrator'))->exists()) {
                    $field = ($data['is_active'] ?? null) === false ? 'is_active' : 'roles';
                    throw ValidationException::withMessages([$field => 'Debe permanecer al menos un administrador activo.']);
                }
                if (isset($data['password'])) {
                    $data['password'] = Hash::make($data['password']);
                    $data['remember_token'] = Str::random(60);
                }
                $user->forceFill($data)->save();
                if ($roles !== null) {
                    $user->roles()->sync($roles);
                }
                if ($employee !== null) {
                    // Guided registration commits the account, roles and employee together.
                    // The caller cannot supply a foreign user_id or other employee attributes.
                    $record = Employee::query()->create([
                        'name' => $employee['name'],
                        'is_active' => $employee['is_active'],
                        'user_id' => $user->id,
                    ]);
                    $employeeContext = ['actor_id' => $actor->id, 'target_id' => $record->id, 'fields' => ['name', 'is_active', 'user_id']];
                    DB::afterCommit(fn () => Log::info('administration.employee.created', $employeeContext));
                }
                if (($data['is_active'] ?? null) === false && config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
                $context = ['actor_id' => $actor->id, 'user_id' => $user->id, 'fields' => array_keys($data), 'roles_changed' => $roles !== null];
                DB::afterCommit(fn () => Log::info($id === null ? 'account.created' : 'account.updated', $context));

                return $user->load('roles', 'employee');
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $field = str_contains($e->getMessage(), 'users_email_unique') ? 'email' : 'login';
                throw ValidationException::withMessages([$field => 'El valor ya está en uso.']);
            }
            throw $e;
        }
    }
}
