<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'description', 'unit'])]
class SparePart extends Model
{
    use HasUuids;

    public $timestamps = false;
}
