<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Application\AccountAdministration;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;

class AccountSecurityTest extends PostgresTestCase
{
    private function administrator(bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['login' => 'security-'.$user->id, 'is_active' => $active])->save();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));

        return $user;
    }

    public function test_last_active_administrator_cannot_be_deactivated_or_demoted(): void
    {
        $admin = $this->administrator();
        $this->administrator(false);
        $this->actingAs($admin);
        $this->patchJson('/api/users/'.$admin->id.'/activation', ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson('/api/users/'.$admin->id, ['roles' => [], 'name' => 'Rejected change'])
            ->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->assertTrue($admin->refresh()->is_active);
        $this->assertNotSame('Rejected change', $admin->name);
        $this->assertTrue($admin->roles()->where('name', 'administrator')->exists());
        $this->patchJson('/api/users/'.$admin->id, ['roles' => $admin->roles()->pluck('id')->all()])->assertOk();
    }

    public function test_another_active_administrator_allows_deactivation_and_demotion(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->actingAs($actor)->patchJson('/api/users/'.$target->id.'/activation', ['is_active' => false])->assertOk();
        $this->patchJson('/api/users/'.$target->id.'/activation', ['is_active' => true])->assertOk();
        $this->patchJson('/api/users/'.$target->id, ['roles' => []])->assertOk();
        $this->assertTrue($actor->refresh()->is_active);
        $this->assertSame(0, $target->roles()->count());
    }

    public function test_removing_role_revokes_administrative_access_for_existing_session(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->actingAs($target)->getJson('/api/users')->assertOk();
        app(AccountAdministration::class)->save($actor, ['roles' => []], $target->id);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/catalogs/customers')->assertForbidden();
        $this->patchJson('/api/users/'.$actor->id, ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/session')->assertOk()->assertJsonPath('data.roles', []);
    }

    public function test_stale_authenticated_account_is_reloaded_after_deactivation(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->actingAs($target)->getJson('/api/session')->assertOk();
        app(AccountAdministration::class)->save($actor, ['is_active' => false], $target->id);
        $this->assertTrue($target->is_active);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_password_change_revokes_existing_session_and_rotates_remember_token(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $target->forceFill(['remember_token' => 'old-remember-token'])->save();
        $oldHash = auth('web')->hashPasswordForCookie($target->password);
        $this->actingAs($target)->withSession(['password_hash_web' => $oldHash])->getJson('/api/session')->assertOk();
        app(AccountAdministration::class)->save($actor, ['password' => 'Changed-password-2026!'], $target->id);
        $this->withSession(['password_hash_web' => $oldHash]);
        $this->getJson('/api/session')->assertUnauthorized();
        $this->assertNotSame('old-remember-token', $target->fresh()->remember_token);
    }

    public function test_write_rechecks_removed_role_inside_transaction(): void
    {
        $admin = $this->administrator();
        $admin->load('roles');
        $admin->roles()->detach();
        $this->expectException(AuthorizationException::class);
        app(AccountAdministration::class)->save($admin, ['name' => 'Denied'], $admin->id);
    }

    public function test_simultaneous_deactivation_demotion_and_mixed_attempts_preserve_one_administrator(): void
    {
        foreach ([[['is_active' => false], ['is_active' => false]], [['roles' => []], ['roles' => []]], [['is_active' => false], ['roles' => []]]] as $changes) {
            $first = $this->administrator();
            $second = $this->administrator();
            $roleIds = Role::pluck('id')->all();
            // Workers need committed fixtures; the parent holds the same lock until both are waiting.
            DB::commit();
            DB::beginTransaction();
            DB::select('SELECT pg_advisory_xact_lock(873210)');
            $processes = [];
            try {
                foreach ([$first, $second] as $index => $admin) {
                    $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql_testing']);
try {
    app(\App\Modules\AccessAndPersonnel\Application\AccountAdministration::class)->save(
        \App\Models\User::findOrFail((int) $argv[1]), json_decode($argv[2], true), (int) $argv[1]
    );
    echo 'saved';
} catch (\Illuminate\Validation\ValidationException $exception) {
    echo 'rejected';
}
CODE;
                    $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, json_encode($changes[$index])], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql_testing', 'LOG_CHANNEL' => 'null']);
                    $process->setTimeout(15);
                    $process->start();
                    $processes[] = $process;
                }
                $deadline = microtime(true) + 10;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE datname = current_database() AND wait_event = 'advisory'")->total;
                    if ($waiting === 2) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $this->assertSame(2, $waiting, 'Ambas operaciones deben competir por el bloqueo transaccional.');
                DB::commit();
                $outcomes = [];
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                    $outcomes[] = $process->getOutput();
                }
                sort($outcomes);
                $this->assertSame(['rejected', 'saved'], $outcomes);
                $this->assertSame(1, User::where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'administrator'))->count());
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
                DB::table('user_roles')->whereIn('user_id', [$first->id, $second->id])->delete();
                User::whereKey([$first->id, $second->id])->delete();
                Role::whereIn('id', $roleIds)->delete();
                DB::beginTransaction();
            }
        }
    }
}
