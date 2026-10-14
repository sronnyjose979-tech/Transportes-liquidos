<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['purchase_request_id', 'spare_part_id', 'workshop_material_need_id', 'code_snapshot', 'description_snapshot', 'unit_snapshot', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }
}
