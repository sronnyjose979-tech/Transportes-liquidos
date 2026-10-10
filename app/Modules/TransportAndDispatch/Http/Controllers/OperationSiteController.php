<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Modules\TransportAndDispatch\Models\OperationSite;
use Illuminate\Database\Eloquent\Model;

class OperationSiteController extends CatalogController
{
    protected function model(): Model
    {
        return new OperationSite;
    }

    protected function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'address' => ['required', 'string', 'max:4096']];
    }
}
