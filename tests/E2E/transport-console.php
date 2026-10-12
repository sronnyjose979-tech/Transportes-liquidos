<?php

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\TransportAndDispatch\Models\Customer;
use App\Modules\TransportAndDispatch\Models\LiquidProduct;
use App\Modules\TransportAndDispatch\Models\OperationSite;
use App\Modules\TransportAndDispatch\Models\Route;
use App\Modules\TransportAndDispatch\Models\Trip;
use App\Modules\TransportAndDispatch\Models\TripUnload;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Instrumentación exclusiva de pruebas CLI.');
}
$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$result = match ($argv[1] ?? '') {
    'fixture' => (function (): array {
        Cache::flush(); // Only the verified temporary E2E file cache, as in the documental fixture.
        $admin = User::where('login', 'e2e-administrator')->firstOrFail();
        $tag = 'E2E-'.Str::uuid();
        $driver = new User;
        $driver->forceFill(['name' => 'Conductor sintético', 'login' => $tag, 'email' => $tag.'@e2e.invalid', 'password' => 'E2e-only-password-42', 'is_active' => true])->save();
        $driver->roles()->attach(Role::where('name', 'driver')->firstOrFail());
        $employee = Employee::create(['name' => $tag, 'user_id' => $driver->id, 'is_active' => true]);
        $fleet = app(FleetAdministration::class);
        $head = $fleet->save($admin, 'tractor_unit', ['code' => $tag.'-CAB']);
        $tank = $fleet->save($admin, 'tanker', ['code' => $tag.'-CIS', 'has_physical_partitions' => false, 'compartments' => [['code' => 'A', 'capacity_liters' => '1000', 'is_active' => true]]]);
        $origin = OperationSite::create(['name' => $tag.'-Origen', 'address' => 'Sintético']);
        $destination = OperationSite::create(['name' => $tag.'-Destino', 'address' => 'Sintético']);
        $route = Route::create(['name' => $tag.'-Ruta', 'origin_site_id' => $origin->id, 'destination_site_id' => $destination->id]);
        $customer = Customer::create(['name' => $tag.'-Cliente']);
        $product = LiquidProduct::create(['name' => $tag.'-Agua', 'is_active' => true]);

        return ['driver_login' => $driver->login, 'compartment' => $tank->tanker->compartments->first()->id, 'driver' => $employee->id, 'head' => $head->id, 'tank' => $tank->id, 'customer' => $customer->id, 'product' => $product->id, 'route' => $route->id, 'origin' => $origin->id, 'destination' => $destination->id];
    })(),
    'partial-unload' => (function () use ($input): array {
        $trip = Trip::findOrFail($input['trip_id']);
        $unload = TripUnload::create([
            'trip_id' => $trip->id, 'trip_load_id' => $trip->tripLoad->id,
            'operation_site_id' => $trip->transportRequest->route->destination_site_id,
            'recorded_by_id' => $trip->registered_by_id, 'occurred_at' => now(), 'reason' => 'Cabecera parcial histórica',
        ])->refresh();

        return ['id' => $unload->id, 'reason' => $unload->reason];
    })(),
    'snapshot' => (function () use ($input): array {
        $trip = Trip::findOrFail($input['trip_id']);

        return ['execution' => $trip->execution_status, 'administration' => $trip->administrative_status, 'drivers' => $trip->driverAssignments()->current()->count(), 'assets' => $trip->assetAssignments()->current()->count(), 'changes' => $trip->changes()->count(), 'loads' => $trip->tripLoad()->count(), 'unloads' => $trip->unload()->count(), 'applications' => DB::table('transport_authorization_applications')->join('transport_changes', 'transport_changes.id', '=', 'transport_authorization_applications.change_id')->where('transport_changes.trip_id', $trip->id)->count()];
    })(),
    default => throw new RuntimeException('Comando no admitido.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);
