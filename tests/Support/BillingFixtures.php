<?php

namespace Tests\Support;

use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BillingFixtures
{
    protected function approvedBillingAmount(Trip $trip, string $amount = '100.00', string $currency = 'CRC'): string
    {
        $id = $this->postJson('/api/billing/trips/'.$trip->id.'/amounts', [
            'request_key' => (string) Str::uuid(), 'amount' => $amount, 'currency' => $currency,
            'reason' => 'Importe acordado sintético',
        ])->assertCreated()->json('data.amount_id');
        $this->postJson('/api/billing/amounts/'.$id.'/decisions', [
            'request_key' => (string) Str::uuid(), 'decision' => 'approved', 'reason' => 'Precio revisado',
        ])->assertOk();

        return $id;
    }

    protected function billingItem(Trip $trip): array
    {
        $id = DB::table('billing_trip_amounts as a')->join('billing_amount_decisions as d', 'd.amount_id', '=', 'a.id')->where('a.trip_id', $trip->id)->where('d.decision', 'approved')->orderByDesc('d.decided_at')->value('a.id');

        return ['trip_id' => $trip->id, 'price_proposal_id' => $id, 'description' => 'Servicio de transporte aprobado'];
    }

    protected function billingInvoice(Trip $trip): string
    {
        return $this->postJson('/api/billing/invoices', [
            'request_key' => (string) Str::uuid(), 'customer_id' => $trip->transportRequest->customer_id,
            'items' => [$this->billingItem($trip)], 'reason' => 'Documento administrativo interno',
        ])->assertCreated()->json('data.invoice_id');
    }

    protected function billingPayment(string $amount, string $currency = 'CRC'): array
    {
        return ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'currency' => $currency,
            'paid_at' => '2026-10-14T08:30:12.123456-06:00', 'reason' => 'Pago recibido sintético', 'method' => 'transferencia'];
    }
}
