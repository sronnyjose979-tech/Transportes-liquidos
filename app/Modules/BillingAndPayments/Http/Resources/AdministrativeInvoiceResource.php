<?php

namespace App\Modules\BillingAndPayments\Http\Resources;

use App\Modules\BillingAndPayments\Application\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdministrativeInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = $this->items->sum(fn ($item) => Money::minor($item->amount));
        $paid = $this->payments->sum(fn ($payment) => Money::minor($payment->amount));

        return ['id' => $this->id, 'document_type' => 'internal_administrative', 'customer' => ['id' => $this->customer_id, 'name' => $this->customer_name_snapshot], 'currency' => $this->currency, 'total' => Money::format($total), 'paid' => Money::format($paid), 'balance' => Money::format($total - $paid), 'status' => $paid === $total ? 'paid' : ($paid === 0 ? 'pending' : 'partially_paid'), 'registered_at' => $this->registered_at, 'created_by_id' => $this->created_by_id, 'reason' => $this->reason, 'items' => $this->items->map(fn ($item) => ['id' => $item->id, 'trip_id' => $item->trip_id, 'price_proposal_id' => $item->price_proposal_id, 'concept' => $item->concept, 'quantity' => '1', 'amount' => $item->amount]), 'payments' => $this->payments->map(fn ($payment) => ['id' => $payment->id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'paid_at' => $payment->paid_at->toIso8601String(), 'registered_at' => $payment->registered_at->toIso8601String(), 'recorded_by_id' => $payment->recorded_by_id, 'reason' => $payment->reason, 'method' => $payment->method])];
    }
}
