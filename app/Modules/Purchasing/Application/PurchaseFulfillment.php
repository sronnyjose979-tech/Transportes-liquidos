<?php

namespace App\Modules\Purchasing\Application;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Support\BoundedTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseFulfillment
{
    public const RELATIONS = ['supplier', 'items.requestedItem', 'receipts.items'];

    public function record(User $actor, array $data, ?string $orderId = null): PurchaseOrder
    {
        $scope = $orderId === null ? 'order_create' : 'receipt_create';
        $key = $orderId === null ? 'purchase_request_item_id' : 'purchase_order_item_id';
        $content = ['target' => $orderId ?? $data['purchase_request_id'], 'reason' => $data['reason'], 'received_at' => $data['received_at'] ?? null, 'items' => array_map(fn ($i) => [$key => $i[$key], 'quantity' => $i['quantity']], $data['items'])];
        $fingerprint = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
        try {
            return app(BoundedTransaction::class)->run(function () use ($actor, $data, $orderId, $scope, $fingerprint): PurchaseOrder {
                DB::select('SELECT pg_advisory_xact_lock(873210)');
                app(WorkshopAccess::class)->office($actor);
                DB::table('transport_assignment_guard')->where('id', 1)->increment('version');
                $prior = DB::table('purchase_fulfillment_operations')->where(['actor_id' => $actor->id, 'scope' => $scope, 'request_key' => $data['request_key']])->first();
                if ($prior) {
                    if ($prior->fingerprint !== $fingerprint) {
                        throw new ConflictHttpException('Clave reutilizada con datos diferentes.');
                    }

                    return PurchaseOrder::with(self::RELATIONS)->findOrFail($prior->purchase_order_id);
                }
                $operationId = (string) Str::uuid();
                $receiptId = null;
                if ($orderId === null) {
                    $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($data['purchase_request_id']);
                    if (! $request->decision()->where('decision', 'approved')->exists()) {
                        throw ValidationException::withMessages(['purchase_request_id' => 'La solicitud requiere aprobación administrativa.']);
                    }
                    $order = PurchaseOrder::create(['purchase_request_id' => $request->id, 'supplier_id' => $request->supplier_id, 'reason' => $data['reason'], 'created_by_id' => $actor->id]);
                    foreach ($data['items'] as $item) {
                        $order->items()->create($item);
                    }
                } else {
                    $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
                    $receipt = PurchaseReceipt::create(['purchase_order_id' => $order->id, 'received_by_id' => $actor->id, 'received_at' => $data['received_at'], 'reason' => $data['reason']]);
                    $receiptId = $receipt->id;
                    foreach ($data['items'] as $line) {
                        $item = PurchaseOrderItem::with('requestedItem')->findOrFail($line['purchase_order_item_id']);
                        $received = $receipt->items()->create($line);
                        app(InventoryLedger::class)->receive($item->requestedItem->spare_part_id, $line['quantity'], $item->requestedItem->unit_snapshot, $received->id, $actor->id, $data['received_at'], $operationId);
                    }
                }
                DB::table('purchase_fulfillment_operations')->insert(['id' => $operationId, 'actor_id' => $actor->id, 'scope' => $scope, 'request_key' => $data['request_key'], 'fingerprint' => $fingerprint, 'purchase_order_id' => $order->id, 'purchase_receipt_id' => $receiptId]);

                return $order->refresh()->load(self::RELATIONS);
            });
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            if (in_array($state, ['40001', '40P01', '55P03', '23505'], true)) {
                throw new ConflictHttpException('Operación concurrente. Consulta el resultado antes de reenviar.');
            }if (in_array($state, ['23503', '23514', 'P0002'], true)) {
                throw ValidationException::withMessages(['purchase' => 'Cantidad, autorización, recepción o conciliación incompatible.']);
            }throw $e;
        }
    }
}
