<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Modules\Inventory\Models\SparePart;
use Illuminate\Database\Eloquent\Model;

class SparePartController extends CatalogController
{
    protected function model(): Model
    {
        return new SparePart;
    }

    protected function rules(): array
    {
        return ['code' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:4096'], 'unit' => ['required', 'string', 'max:255']];
    }
}
