<?php

namespace App\Modules\FleetAndDocuments\Http\Controllers;

class TractorUnitController extends FleetController
{
    protected function assetType(): string
    {
        return 'tractor_unit';
    }
}
