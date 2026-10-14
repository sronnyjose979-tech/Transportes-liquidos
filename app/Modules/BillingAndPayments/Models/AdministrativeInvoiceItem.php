<?php

namespace App\Modules\BillingAndPayments\Models;

use Illuminate\Database\Eloquent\Model;

final class AdministrativeInvoiceItem extends Model
{
    protected $table = 'billing_invoice_items';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
