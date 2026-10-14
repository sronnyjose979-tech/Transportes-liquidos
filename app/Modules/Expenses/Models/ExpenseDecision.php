<?php

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;

final class ExpenseDecision extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];
}
