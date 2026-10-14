<?php

use App\Models\User;
use App\Modules\TransportAndDispatch\Application\TripOperations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransportFixtures;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Instrumentación exclusiva de pruebas CLI.');
}
$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
require_once dirname(__DIR__).'/Support/TransportFixtures.php';
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$fixture = new class
{
    use TransportFixtures;

    public function seed(): array
    {
        return DB::transaction(function (): array {
            Cache::flush(); // Verified temporary E2E file cache only.
            $admin = User::where('login', 'e2e-administrator')->firstOrFail();
            $request = $this->request($admin);
            $trip = $this->trip($request);
            $this->ensureAssignments($trip);
            $operations = app(TripOperations::class);
            $operations->start($admin, $trip->id, 'Inicio oficial sintético');
            $this->operationalRecords($trip->refresh());
            $operations->finish($admin, $trip->id, 'Finalización oficial con carga y descarga');

            return ['trip_id' => $trip->id, 'customer_id' => $request->customer_id];
        });
    }
};
$result = match ($argv[1] ?? '') {
    'fixture' => $fixture->seed(),
    'snapshot' => ['invoices' => DB::table('billing_invoices')->count(), 'payments' => DB::table('billing_payments')->where('invoice_id', $input['invoice_id'])->count(), 'amounts' => DB::table('billing_trip_amounts')->where('trip_id', $input['trip_id'])->count()],
    default => throw new RuntimeException('Comando no admitido.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);
