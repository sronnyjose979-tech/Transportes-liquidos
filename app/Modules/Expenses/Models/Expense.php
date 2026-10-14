<?php

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;

final class Expense extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public function revisions()
    {
        return $this->hasMany(ExpenseRevision::class)->orderBy('registered_at')->orderBy('id');
    }

    public function receipts()
    {
        return $this->hasMany(ExpenseReceipt::class)->orderBy('registered_at')->orderBy('id');
    }
}
