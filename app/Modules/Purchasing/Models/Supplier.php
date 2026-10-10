<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class Supplier extends Model
{
    use HasUuids;

    public $timestamps = false;
}
