<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['purchase_order_id', 'received_by_id', 'received_at', 'reason'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'received_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }
}
