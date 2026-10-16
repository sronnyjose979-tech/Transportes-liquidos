<?php

namespace App\Modules\AdministrativeReports\Http\Controllers;

use App\Modules\AdministrativeReports\Application\AdministrativeReports;
use App\Modules\AdministrativeReports\Http\Requests\ReportQueryRequest;
use App\Modules\AdministrativeReports\Http\Resources\ReportResource;

final class ReportController
{
    public function show(ReportQueryRequest $request, AdministrativeReports $reports, string $kind): ReportResource
    {
        return new ReportResource($reports->consult($kind, $request->validated()));
    }
}
