<?php

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;

final class ExpenseReceipt extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['storage_key'];
}
