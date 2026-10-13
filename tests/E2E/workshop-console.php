<?php

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Employee;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Models\Supplier;
use Carbon\CarbonImmutable;
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
        Cache::flush();
        // Use wall time: assignment queries use PostgreSQL CURRENT_TIMESTAMP.
        $clock = getenv('TRANSPORTE_E2E_ROOT').'/clock';
        if (is_file($clock)) {
            unlink($clock);
        }
        CarbonImmutable::setTestNow();
        $tag = 'E2E-TALLER-'.Str::uuid();
        $admin = User::where('login', 'e2e-administrator')->firstOrFail();
        $mechanic = User::where('login', 'e2e-mechanic')->firstOrFail();
        $employee = Employee::firstOrCreate(['user_id' => $mechanic->id], ['name' => 'Mecánico E2E Taller', 'is_active' => true]);
        $asset = app(FleetAdministration::class)->save($admin, 'tractor_unit', ['code' => $tag]);
        $part = SparePart::create(['code' => $tag.'-REP', 'description' => 'Filtro sintético '.$tag, 'unit' => 'unidad']);
        $supplier = Supplier::create(['name' => 'Proveedor sintético '.$tag]);

        return ['tag' => $tag, 'asset' => $asset->only('id', 'code'), 'employee' => $employee->only('id', 'name'),
            'part' => $part->only('id', 'code', 'description'), 'supplier' => $supplier->only('id', 'name'),
            'starts_local' => now()->subHour()->setTimezone('America/Costa_Rica')->format('Y-m-d\TH:i')];
    })(),
    'snapshot' => (function () use ($input): array {
        $orders = DB::table('workshop_work_orders')->where('fleet_asset_id', $input['asset_id'])->get();
        $ids = $orders->pluck('id');
        $scopes = DB::table('workshop_scopes')->whereIn('work_order_id', $ids)->get();
        $requests = DB::table('workshop_authorization_requests')->whereIn('work_order_id', $ids)->get();
        $decisions = DB::table('workshop_authorization_decisions')->whereIn('request_id', $requests->pluck('id'))->get();

        return ['orders' => $orders, 'reports' => DB::table('workshop_reports')->where('fleet_asset_id', $input['asset_id'])->get(),
            'links' => DB::table('workshop_report_links')->whereIn('work_order_id', $ids)->get(),
            'assignments' => DB::table('workshop_mechanic_assignments')->whereIn('work_order_id', $ids)->get(),
            'diagnoses' => DB::table('workshop_diagnoses')->whereIn('work_order_id', $ids)->get(), 'scopes' => $scopes,
            'parts' => DB::table('workshop_scope_parts')->whereIn('scope_id', $scopes->pluck('id'))->get(),
            'tasks' => DB::table('workshop_scope_tasks')->whereIn('scope_id', $scopes->pluck('id'))->get(),
            'requests' => $requests, 'decisions' => $decisions,
            'applications' => DB::table('workshop_authorization_applications')->whereIn('decision_id', $decisions->pluck('id'))->get(),
            'receipts' => DB::table('workshop_operations')->whereIn('work_order_id', $ids)->get()];
    })(),
    default => throw new RuntimeException('Comando no admitido.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);
