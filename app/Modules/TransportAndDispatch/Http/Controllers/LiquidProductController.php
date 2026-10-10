<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Modules\TransportAndDispatch\Models\LiquidProduct;
use Illuminate\Database\Eloquent\Model;

class LiquidProductController extends CatalogController
{
    protected function model(): Model
    {
        return new LiquidProduct;
    }

    protected function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']];
    }
}
