<?php

namespace App\Modules\FleetAndDocuments\Domain;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DocumentUploadConflict extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Clave reutilizada con contenido distinto. Consulte el resultado de la operación original.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409)->header('Cache-Control', 'private, no-store');
    }
}
