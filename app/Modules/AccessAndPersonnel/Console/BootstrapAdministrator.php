<?php

namespace App\Modules\AccessAndPersonnel\Console;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BootstrapAdministrator extends Command
{
    protected $signature = 'access:bootstrap-admin';

    protected $description = 'Crear el primer administrador mediante consola local y contraseña oculta';

    public function handle(): int
    {
        if (User::query()->whereHas('roles', fn ($query) => $query->where('name', 'administrator'))->exists()) {
            $this->error('Ya existe un administrador; utilice la administración autenticada.');

            return self::FAILURE;
        }
        if (! $this->confirm('¿Crear el primer administrador en la base configurada?')) {
            return self::FAILURE;
        }
        $data = ['login' => $this->ask('Login'), 'name' => $this->ask('Nombre de cuenta (compatibilidad)'), 'email' => $this->ask('Correo'), 'password' => $this->secret('Contraseña (12–72 bytes)'), 'password_confirmation' => $this->secret('Confirmar contraseña')];
        $validator = Validator::make($data, ['login' => ['required', 'string', 'max:255', Rule::unique('users', 'login')], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')], 'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', function ($a, $v, $fail) {
            if (is_string($v) && strlen($v) > 72) {
                $fail('Contraseña demasiado larga.');
            }
        }]], ['required' => 'Falta un campo obligatorio.', 'unique' => 'El identificador ya está en uso.', 'confirmed' => 'La confirmación no coincide.', 'min' => 'Contraseña demasiado corta.', 'max' => 'Campo demasiado largo.', 'email' => 'Correo no válido.']);
        if ($validator->fails()) {
            $this->error('Datos inválidos; no se creó ninguna cuenta.');

            return self::FAILURE;
        }
        try {
            $created = DB::transaction(function () use ($data) {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                if (User::query()->whereHas('roles', fn ($query) => $query->where('name', 'administrator'))->exists()) {
                    return false;
                }
                foreach (Role::SUPPORTED_NAMES as $name) {
                    Role::firstOrCreate(['name' => $name]);
                }
                $role = Role::where('name', 'administrator')->firstOrFail();
                $user = new User;
                unset($data['password_confirmation']);
                $data['password'] = Hash::make($data['password']);
                $user->forceFill([...$data, 'is_active' => true])->save();
                $user->roles()->attach($role);
                DB::afterCommit(fn () => Log::info('account.bootstrap', ['user_id' => $user->id, 'actor' => 'local-console']));

                return true;
            });
        } catch (QueryException $exception) {
            $this->error('No se pudo crear la cuenta; compruebe el destino y los identificadores.');

            return self::FAILURE;
        }
        if (! $created) {
            $this->error('Ya existe un administrador; utilice la administración autenticada.');

            return self::FAILURE;
        }
        $this->info('Administrador creado. No se muestran credenciales.');

        return self::SUCCESS;
    }
}
