<?php

namespace App\Modules\TransportAndDispatch\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'address'])]
class OperationSite extends Model
{
    use HasUuids;

    public $timestamps = false;
}
