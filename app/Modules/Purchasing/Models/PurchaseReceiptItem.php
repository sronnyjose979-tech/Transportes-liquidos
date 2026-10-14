<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['purchase_receipt_id', 'purchase_order_item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'received_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }
}
