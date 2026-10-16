<?php

use App\Modules\BillingAndPayments\Application\BillingPayments;
use App\Modules\Expenses\Application\ExpenseAdministration;
use App\Modules\Inventory\Models\SparePart;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InventoryFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\TransportFixtures;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Instrumentación exclusiva de pruebas CLI.');
}
$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
$result = match ($argv[1] ?? '') {
    'fixture' => (new class
    {
        use TransportFixtures;

        public function build(): array
        {
            $trip = DB::transaction(fn () => $this->closedTrip());
            $actor = $trip->registeredBy;
            $billing = app(BillingPayments::class);
            $amount = $billing->propose($actor, $trip->id, (string) Str::uuid(), ['amount' => '100.03', 'currency' => 'CRC', 'reason' => 'Importe sintético']);
            $billing->decide($actor, $amount['amount_id'], (string) Str::uuid(), ['decision' => 'approved', 'reason' => 'Revisión sintética']);
            $invoice = $billing->invoice($actor, (string) Str::uuid(), ['customer_id' => $trip->transportRequest->customer_id, 'reason' => 'Documento interno',
                'items' => [['trip_id' => $trip->id, 'price_proposal_id' => $amount['amount_id'], 'description' => 'Servicio sintético']]]);
            foreach (['0.01', '0.02'] as $payment) {
                $billing->payment($actor, $invoice['invoice_id'], (string) Str::uuid(), ['amount' => $payment, 'currency' => 'CRC',
                    'paid_at' => '2026-10-14T14:00:00Z', 'reason' => 'Cobro sintético']);
            }
            foreach (['CRC', 'USD'] as $currency) {
                app(ExpenseAdministration::class)->create($actor, (string) Str::uuid(), ['trip_id' => $trip->id, 'concept' => 'Gasto sintético '.$currency, 'category' => 'Operación',
                    'amount' => '1.02', 'currency' => $currency, 'occurred_at' => '2026-10-14T14:00:00Z', 'description' => 'No pagado']);
            }
            $asset = $trip->assetAssignments()->where('asset_type', 'tractor_unit')->firstOrFail()->fleet_asset_id;
            $order = ReportFixtures::workshop($actor, $asset);
            $part = SparePart::create(['code' => 'REPORT-'.Str::uuid(), 'description' => 'Repuesto sintético', 'unit' => 'unidad']);
            InventoryFixtures::reconcile($actor, $part, '2.123456');

            return ['trip_id' => $trip->id, 'customer_id' => $trip->transportRequest->customer_id, 'invoice_id' => $invoice['invoice_id'],
                'asset_id' => $asset, 'order_id' => $order, 'part_id' => $part->id];
        }
    })->build(),
    'snapshot' => (function (): array {
        $result = [];
        foreach (['trips', 'billing_invoices', 'billing_payments', 'billing_operations', 'expenses', 'expense_revisions', 'expense_operations', 'workshop_work_orders', 'workshop_activities', 'workshop_operations', 'inventory_movements'] as $table) {
            $result[$table] = DB::table($table)->count();
        }

        return $result;
    })(),
    default => throw new RuntimeException('Comando no admitido.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);
