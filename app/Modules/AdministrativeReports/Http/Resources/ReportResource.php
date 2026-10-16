<?php

namespace App\Modules\AdministrativeReports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
