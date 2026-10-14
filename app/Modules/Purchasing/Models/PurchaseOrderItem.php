<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'purchase_request_item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'received_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function requestedItem()
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    public function receiptItems()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }
}
