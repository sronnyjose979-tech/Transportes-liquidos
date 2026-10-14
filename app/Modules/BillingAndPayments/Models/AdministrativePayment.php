<?php

namespace App\Modules\BillingAndPayments\Models;

use Illuminate\Database\Eloquent\Model;

final class AdministrativePayment extends Model
{
    protected $table = 'billing_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime'];
    }
}
