<?php

namespace App\Modules\BillingAndPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AdministrativeInvoice extends Model
{
    protected $table = 'billing_invoices';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function items(): HasMany
    {
        return $this->hasMany(AdministrativeInvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AdministrativePayment::class, 'invoice_id');
    }
}
