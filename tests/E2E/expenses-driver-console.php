<?php

use App\Models\User;
use App\Modules\TransportAndDispatch\Application\AssignmentAdministration;
use App\Modules\TransportAndDispatch\Application\TransportAdministration;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TransportFixtures;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Instrumentación exclusiva de pruebas CLI.');
}
$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$result = match ($argv[1] ?? '') {
    'fixture' => (new class
    {
        use TransportFixtures;

        public function build(): array
        {
            return DB::transaction(function (): array {
                $admin = User::where('login', 'e2e-administrator')->firstOrFail();
                $trip = $this->trip($this->request($admin));
                $this->ensureAssignments($trip);
                app(TripOperations::class)->start($admin, $trip->id, 'Inicio oficial sintético');
                $driver = $trip->driverAssignments()->current()->firstOrFail()->employee->user;
                $driver->forceFill(['login' => 'E2E-driver-'.Str::uuid(), 'password' => 'E2e-only-password-42'])->save();

                return ['trip_id' => $trip->id, 'driver_login' => $driver->login, 'driver_id' => $driver->id,
                    'assignment_id' => $trip->assetAssignments()->current()->where('asset_type', 'tractor_unit')->firstOrFail()->id];
            });
        }
    })->build(),
    'release' => (function () use ($input): array {
        $admin = User::where('login', 'e2e-administrator')->firstOrFail();
        $trip = Trip::findOrFail($input['trip_id']);
        if ($trip->execution_status === 'in_progress') {
            app(TransportAdministration::class)->tripAction($admin, $trip->id, 'cancel', ['version' => $trip->version, 'reason' => 'Cancelación E2E antes de liberación']);
        }
        app(AssignmentAdministration::class)->release($admin, $trip->id, 'driver', 'Liberación manual E2E');

        return ['released' => true];
    })(),
    'snapshot' => (function () use ($input): array {
        $trip = Trip::findOrFail($input['trip_id']);
        $expenses = DB::table('expenses')->where('trip_id', $trip->id)->pluck('id');

        return ['reports' => $trip->events()->count(), 'readings' => DB::table('trip_odometer_readings')->where('trip_id', $trip->id)->count(),
            'expenses' => $expenses->count(), 'receipts' => DB::table('expense_receipts')->whereIn('expense_id', $expenses)->count(),
            'execution' => $trip->execution_status, 'drivers' => $trip->driverAssignments()->current()->count(),
            'orphan_files' => count(array_diff(Storage::disk('fleet_documents')->files('expenses/files'), DB::table('expense_receipts')->pluck('storage_key')->all())),
            'pending_files' => count(Storage::disk('fleet_documents')->files('expenses/.pending'))];
    })(),
    default => throw new RuntimeException('Comando no admitido.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);
