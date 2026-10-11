<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

class TankerController extends FleetController
{
    protected function assetType(): string
    {
        return 'tanker';
    }
}
