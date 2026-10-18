<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Inspector exclusivo de pruebas CLI.');
}
$app = require __DIR__.'/bootstrap.php'; // Verifies private PG18 identity before any query.
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$result = DB::transaction(function () use ($input): array {
    DB::statement('SET TRANSACTION READ ONLY');
    $result = [];
    $allowed = ['customers', 'liquid_products', 'operation_sites', 'routes', 'employees', 'users',
        'tractor_units', 'tankers', 'transport_requests', 'trips', 'expenses', 'billing_invoices',
        'billing_payments', 'purchase_requests', 'purchase_orders'];
    foreach ($input['records'] ?? [] as $table => $ids) {
        if (! in_array($table, $allowed, true) || ! is_array($ids) || count($ids) > 100) {
            throw new RuntimeException('Consulta no permitida.');
        }
        $columns = match ($table) {
            'users' => ['id', 'login', 'is_active'], 'employees' => ['id', 'user_id', 'is_active'],
            'trips' => ['id', 'execution_status', 'administrative_status'],
            default => ['id'],
        };
        $result[$table] = DB::table($table)->whereIn('id', $ids)->get($columns)->all();
    }
    if ($trip = $input['records']['trips'][0] ?? null) {
        $result['trip_expenses'] = DB::table('expenses')->where('trip_id', $trip)->pluck('id')->all();
        $result['trip_readings'] = DB::table('trip_odometer_readings')->where('trip_id', $trip)->count();
    }
    if ($invoice = $input['records']['billing_invoices'][0] ?? null) {
        $result['payments'] = DB::table('billing_payments')->where('invoice_id', $invoice)->get(['id', 'amount', 'currency'])->all();
        $result['invoice_items'] = DB::table('billing_invoice_items')->where('invoice_id', $invoice)->get(['id', 'trip_id', 'amount'])->all();
    }
    if ($part = $input['spare_part_id'] ?? null) {
        $result['stock'] = DB::table('inventory_movements')->where('spare_part_id', $part)
            ->selectRaw('sum(warehouse_delta)::text AS warehouse, sum(custody_delta)::text AS custody, sum(consumed_delta)::text AS consumed')->first();
        $result['movements'] = DB::table('inventory_movements')->where('spare_part_id', $part)->get(['id', 'kind', 'warehouse_delta', 'custody_delta', 'consumed_delta'])->all();
    }
    if ($input['inventory'] ?? false) {
        $result['endpoints'] = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $result['endpoints'][] = ['methods' => $route->methods(), 'uri' => '/'.$route->uri(),
                    'action' => $route->getActionName(), 'middleware' => $route->gatherMiddleware()];
            }
        }
    }

    return $result;
});
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
