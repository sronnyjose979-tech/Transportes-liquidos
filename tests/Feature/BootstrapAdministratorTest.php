<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Support\Facades\Hash;
use Tests\PostgresTestCase;

class BootstrapAdministratorTest extends PostgresTestCase
{
    public function test_console_can_provision_the_first_administrator(): void
    {
        $this->artisan('access:bootstrap-admin')
            ->expectsConfirmation('¿Crear el primer administrador en la base configurada?', 'yes')
            ->expectsQuestion('Login', 'bootstrap-admin')
            ->expectsQuestion('Nombre de cuenta (compatibilidad)', 'Administrador')
            ->expectsQuestion('Correo', 'bootstrap@example.test')
            ->expectsQuestion('Contraseña (12–72 bytes)', 'Safe-bootstrap-2026!')
            ->expectsQuestion('Confirmar contraseña', 'Safe-bootstrap-2026!')
            ->assertExitCode(0);
        $user = User::where('login', 'bootstrap-admin')->firstOrFail();
        $this->assertSame(4, Role::count());
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('Safe-bootstrap-2026!', $user->password));
        $this->assertSame('administrator', $user->roles()->first()->name);
    }

    public function test_cancelled_bootstrap_does_not_create_accounts(): void
    {
        $this->artisan('access:bootstrap-admin')
            ->expectsConfirmation('¿Crear el primer administrador en la base configurada?', 'no')
            ->assertExitCode(1);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Role::count());
    }

    public function test_existing_administrator_cannot_be_replaced_or_bypassed(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::create(['name' => 'administrator']));
        $this->artisan('access:bootstrap-admin')->assertExitCode(1);
        $this->assertSame(1, User::count());
        $this->assertSame($user->id, User::first()->id);
    }

    public function test_invalid_password_creates_neither_account_nor_role(): void
    {
        $this->artisan('access:bootstrap-admin')
            ->expectsConfirmation('¿Crear el primer administrador en la base configurada?', 'yes')
            ->expectsQuestion('Login', 'bootstrap-admin')
            ->expectsQuestion('Nombre de cuenta (compatibilidad)', 'Administrador')
            ->expectsQuestion('Correo', 'bootstrap@example.test')
            ->expectsQuestion('Contraseña (12–72 bytes)', 'short')
            ->expectsQuestion('Confirmar contraseña', 'short')
            ->assertExitCode(1);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Role::count());
    }
}
