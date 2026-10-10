<?php

namespace App\Modules\TransportAndDispatch\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Modules\TransportAndDispatch\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class CustomerController extends CatalogController
{
    protected function model(): Model
    {
        return new Customer;
    }

    protected function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
