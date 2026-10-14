<?php

namespace App\Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'purchase_request_id' => $this->purchase_request_id, 'supplier_id' => $this->supplier_id, 'supplier' => ['id' => $this->supplier->id, 'name' => $this->supplier->name], 'reason' => $this->reason, 'created_by_id' => $this->created_by_id, 'registered_at' => $this->registered_at?->toIso8601String(), 'items' => $this->items->map(function ($item) {
            $source = $item->requestedItem;

            return ['id' => $item->id, 'purchase_request_item_id' => $source->id, 'spare_part_id' => $source->spare_part_id, 'code' => $source->code_snapshot, 'description' => $source->description_snapshot, 'unit' => $source->unit_snapshot, 'quantity' => $item->quantity, 'authorized_quantity' => $source->quantity, 'received_quantity' => $item->received_quantity, 'pending_quantity' => $item->pending_quantity];
        }), 'receipts' => $this->receipts->map(fn ($receipt) => ['id' => $receipt->id, 'received_by_id' => $receipt->received_by_id, 'received_at' => $receipt->received_at->toIso8601String(), 'reason' => $receipt->reason, 'items' => $receipt->items->map(fn ($item) => ['id' => $item->id, 'purchase_order_item_id' => $item->purchase_order_item_id, 'quantity' => $item->quantity])])];
    }
}
