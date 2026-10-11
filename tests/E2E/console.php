<?php

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\DocumentAdministration;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Models\Document;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('La instrumentación E2E no es un endpoint HTTP.');
}
$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
$command = $argv[1] ?? '';
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$workspace = getenv('TRANSPORTE_E2E_ROOT');
$result = match ($command) {
    'prepare' => (function (): array {
        if (Artisan::call('migrate', ['--database' => 'pgsql_testing', '--force' => true]) !== 0) {
            throw new RuntimeException('No se completaron las migraciones del clúster E2E.');
        }
        foreach (['administrator', 'secretary', 'driver', 'mechanic'] as $name) {
            $user = new User;
            $user->forceFill(['name' => 'E2E '.$name, 'login' => 'e2e-'.$name,
                'email' => $name.'@e2e.invalid', 'password' => 'E2e-only-password-42', 'is_active' => true])->save();
            $user->roles()->attach(Role::firstOrCreate(['name' => $name]));
        }

        return ['prepared' => true, 'postgres' => DB::selectOne('SHOW server_version')->server_version];
    })(),
    'fixture' => (function () use ($input, $workspace): array {
        Cache::flush(); // The file cache is inside this verified temporary workspace only.
        file_put_contents($workspace.'/clock', '2026-10-12T05:59:59+00:00');
        $admin = User::where('login', 'e2e-administrator')->firstOrFail();
        $tag = $input['tag'];
        if (! preg_match('/\AE2E-\d{4}\z/', $tag)) {
            throw new RuntimeException('Etiqueta sintética inválida.');
        }
        $fleet = app(FleetAdministration::class);
        $tractor = $fleet->save($admin, 'tractor_unit', ['code' => $tag.'-CAB']);
        $tanker = $fleet->save($admin, 'tanker', ['code' => $tag.'-CIS', 'has_physical_partitions' => false,
            'compartments' => [['code' => 'A', 'capacity_liters' => '1000', 'is_active' => true]]]);
        $types = [];
        foreach ([true, false] as $expiration) {
            $types[] = app(DocumentAdministration::class)->configure($admin, ['name' => $tag.($expiration ? '-DEKRA' : '-PERMISO'),
                'tracks_expiration' => $expiration, 'required_for_tractor_units' => true, 'required_for_tankers' => true])->toArray();
        }

        return ['tractor' => $tractor->only('id', 'code', 'type'), 'tanker' => $tanker->only('id', 'code', 'type'), 'types' => $types];
    })(),
    'inspect' => (function () use ($input): array {
        $id = $input['asset_id'];
        $documents = DB::table('documents')->where('fleet_asset_id', $id)->orderBy('id')->get();
        $disk = Storage::disk('fleet_documents');

        return ['documents' => $documents,
            'snapshots' => DB::table('document_configuration_snapshots')->whereIn('document_id', $documents->pluck('id'))->orderBy('document_id')->get(),
            'current' => DB::table('current_fleet_documents')->where('fleet_asset_id', $id)->orderBy('document_type_id')->get(),
            'files' => $documents->mapWithKeys(fn ($doc) => [$doc->id => $disk->exists($doc->storage_key) ? hash_file('sha256', $disk->path($doc->storage_key)) : null]),
            'pending' => count($disk->files('.pending'))];
    })(),
    'expire-sessions' => (function () use ($input): array {
        $user = User::where('login', 'e2e-'.$input['role'])->firstOrFail();

        return ['expired' => DB::table('sessions')->where('user_id', $user->id)->update(['last_activity' => 0])];
    })(),
    'clock' => (function () use ($input, $workspace): array {
        if (! in_array($input['at'], ['2026-10-12T05:59:59+00:00', '2026-10-12T06:00:00+00:00', '2026-10-27T06:00:00+00:00'], true)) {
            throw new RuntimeException('Fecha de reloj E2E no admitida.');
        }
        file_put_contents($workspace.'/clock', $input['at']);

        return ['clock' => $input['at']];
    })(),
    'hold' => (function () use ($workspace): array {
        DB::transaction(function () use ($workspace): void {
            DB::select('SELECT pg_advisory_xact_lock(873210)');
            file_put_contents($workspace.'/lock-held', '1');
            // Tests release this gate once real HTTP requests have reached the database.
            $deadline = microtime(true) + 15;
            while (! is_file($workspace.'/lock-release') && microtime(true) < $deadline) {
                usleep(20_000);
            }
        });

        return ['released' => true];
    })(),
    'waiting' => ['requests' => (int) DB::selectOne("SELECT count(*) AS waiting FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND query LIKE '%pg_advisory_xact_lock(873210)%'")->waiting],
    'guards' => (function () use ($input): array {
        $states = [];
        foreach (['documents' => ['storage_key' => 'files/forbidden.pdf'], 'document_configuration_snapshots' => ['name' => 'Forbidden']] as $table => $values) {
            try {
                DB::transaction(fn () => DB::table($table)->where($table === 'documents' ? 'id' : 'document_id', $input['id'])->update($values));
                throw new RuntimeException('La escritura histórica no fue rechazada.');
            } catch (QueryException $error) {
                $states[$table] = $error->errorInfo[0];
            }
        }

        return $states;
    })(),
    'fail-persistence' => (function () use ($input): array {
        $actor = User::where('login', 'e2e-secretary')->firstOrFail();
        $file = new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.pdf', 'valid.pdf', null, null, true);
        $disk = Storage::disk('fleet_documents');
        $before = $disk->allFiles('files');
        try {
            app(DocumentAdministration::class)->register($actor, $input['asset_id'], ['document_type_id' => $input['type_id'],
                'issued_on' => '2028-01-01', 'expires_on' => '2027-01-01'], $file);
            throw new RuntimeException('PostgreSQL debía rechazar las fechas.');
        } catch (ValidationException) {
            return ['rejected' => true, 'files_unchanged' => $before === $disk->allFiles('files'), 'pending' => count($disk->files('.pending'))];
        }
    })(),
    'reconcile' => (function () use ($input): array {
        $document = Document::findOrFail($input['id']);
        $disk = Storage::disk('fleet_documents');
        $orphan = (string) Str::uuid();
        $orphanKey = 'files/'.$orphan.'.pdf';
        $disk->put($orphanKey, 'E2E interrupted upload');
        foreach ([[$orphan, $orphanKey], [$document->id, $document->storage_key]] as [$id, $key]) {
            $disk->put('.pending/'.$id.'.json', json_encode(['document_id' => $id, 'key' => $key, 'pending_commit' => false], JSON_THROW_ON_ERROR));
        }
        $first = Artisan::call('fleet-documents:reconcile-storage');
        $second = Artisan::call('fleet-documents:reconcile-storage');

        return ['exit_codes' => [$first, $second], 'historical_exists' => $disk->exists($document->storage_key),
            'orphan_exists' => $disk->exists($orphanKey), 'pending' => count($disk->files('.pending'))];
    })(),
    default => throw new RuntimeException('Comando E2E desconocido.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
