<?php

namespace App\Modules\AdministrativeAudit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
