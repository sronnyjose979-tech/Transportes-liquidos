<?php

namespace App\Modules\FleetAndDocuments\Domain;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DocumentOperationFailed extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => 'No se pudo completar la operación documental. Intente nuevamente o contacte al administrador.'], 503)
            ->header('Cache-Control', 'no-store');
    }
}
