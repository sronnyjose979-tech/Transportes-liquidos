<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['purchase_request_id', 'supplier_id', 'reason', 'created_by_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'received_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class)->select('purchase_order_items.*')->selectRaw('COALESCE((SELECT sum(r.quantity) FROM purchase_receipt_items r WHERE r.purchase_order_item_id=purchase_order_items.id),0)::text AS received_quantity')->selectRaw('(quantity-COALESCE((SELECT sum(r.quantity) FROM purchase_receipt_items r WHERE r.purchase_order_item_id=purchase_order_items.id),0))::text AS pending_quantity');
    }

    public function receipts()
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
