<?php

namespace App\Modules\Purchasing\Application;

use App\Models\User;
use App\Modules\Inventory\Models\SparePart;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestDecision;
use App\Support\BoundedTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseRequestAdministration
{
    public const RELATIONS = ['supplier', 'requestedBy', 'items', 'decision.decidedBy'];

    public function record(User $actor, array $data, ?string $requestId = null): PurchaseRequest
    {
        $scope = $requestId === null ? 'request_create' : 'request_decide';
        $content = array_diff_key($data, ['request_key' => true]);
        // Explicitly ordered validated fields yield a stable fingerprint, regardless of JSON key order.
        $content = $requestId === null
            ? ['supplier_id' => $content['supplier_id'], 'reason' => $content['reason'], 'items' => array_map(fn (array $item): array => [
                'spare_part_id' => $item['spare_part_id'], 'quantity' => $item['quantity'], 'workshop_material_need_id' => $item['workshop_material_need_id'] ?? null,
            ], $content['items'])]
            : ['purchase_request_id' => $requestId, 'decision' => $content['decision'], 'reason' => $content['reason']];
        $fingerprint = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
        try {
            return app(BoundedTransaction::class)->run(function () use ($actor, $data, $requestId, $scope, $fingerprint): PurchaseRequest {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                Gate::forUser(User::findOrFail($actor->id))->authorize($requestId === null ? 'manage-purchase-requests' : 'decide-purchase-request');
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $operation = DB::table('purchasing_operations')->where(['actor_id' => $actor->id, 'scope' => $scope, 'request_key' => $data['request_key']])->first();
                if ($operation !== null) {
                    if ($operation->fingerprint !== $fingerprint) {
                        throw new ConflictHttpException('La clave corresponde a otra operación. Consulta el resultado antes de reenviar.');
                    }

                    return PurchaseRequest::with(self::RELATIONS)->findOrFail($operation->purchase_request_id);
                }
                $decisionId = null;
                if ($requestId === null) {
                    $purchase = PurchaseRequest::create(['supplier_id' => $data['supplier_id'], 'reason' => $data['reason'], 'requested_by_id' => $actor->id]);
                    foreach ($data['items'] as $item) {
                        $part = SparePart::findOrFail($item['spare_part_id']);
                        $purchase->items()->create(['spare_part_id' => $part->id, 'quantity' => $item['quantity'], 'workshop_material_need_id' => $item['workshop_material_need_id'] ?? null,
                            'code_snapshot' => $part->code, 'description_snapshot' => $part->description, 'unit_snapshot' => $part->unit]);
                    }
                } else {
                    $purchase = PurchaseRequest::query()->lockForUpdate()->findOrFail($requestId);
                    if ($purchase->decision()->exists()) {
                        throw new ConflictHttpException('La solicitud ya tiene una decisión definitiva.');
                    }
                    $decisionId = PurchaseRequestDecision::create(['purchase_request_id' => $purchase->id, 'decision' => $data['decision'], 'reason' => $data['reason'], 'decided_by_id' => $actor->id])->id;
                }
                DB::table('purchasing_operations')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'scope' => $scope,
                    'request_key' => $data['request_key'], 'fingerprint' => $fingerprint, 'purchase_request_id' => $purchase->id, 'decision_id' => $decisionId, 'reason' => $data['reason'], 'confirmed_result' => json_encode(['purchase_request_id' => $purchase->id, 'decision_id' => $decisionId], JSON_THROW_ON_ERROR)]);

                return $purchase->refresh()->load(self::RELATIONS);
            });
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? '', ['40001', '40P01', '55P03', '23505'], true)) {
                throw new ConflictHttpException('Operación concurrente o previamente registrada. Consulta el estado antes de reenviar.');
            }
            if (in_array($exception->errorInfo[0] ?? '', ['23503', '23514', 'P0002'], true)) {
                throw ValidationException::withMessages(['purchase' => 'La solicitud no cumple las referencias, cantidades o restricciones existentes.']);
            }
            Log::warning('purchasing.database_operation_rejected', ['sqlstate' => $exception->errorInfo[0] ?? null]);
            abort(500, 'No se pudo confirmar la operación de compra. Consulta el estado antes de reenviar.');
        }
    }
}
