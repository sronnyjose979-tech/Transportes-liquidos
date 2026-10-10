<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierController extends CatalogController
{
    protected function model(): Model
    {
        return new Supplier;
    }

    protected function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
