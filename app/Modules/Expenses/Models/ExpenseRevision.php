<?php

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;

final class ExpenseRevision extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public function decision()
    {
        return $this->hasOne(ExpenseDecision::class, 'revision_id');
    }
}
