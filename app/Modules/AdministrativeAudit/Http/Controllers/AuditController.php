<?php

namespace App\Modules\AdministrativeAudit\Http\Controllers;

use App\Modules\AdministrativeAudit\Application\AdministrativeAudit;
use App\Modules\AdministrativeAudit\Http\Requests\AuditQueryRequest;
use App\Modules\AdministrativeAudit\Http\Resources\AuditResource;

final class AuditController
{
    public function index(AuditQueryRequest $request, AdministrativeAudit $audit): AuditResource
    {
        return new AuditResource($audit->consult($request->validated()));
    }

    public function show(AuditQueryRequest $request, AdministrativeAudit $audit, string $source, string $id): AuditResource
    {
        return new AuditResource($audit->detail($source, $id));
    }
}
