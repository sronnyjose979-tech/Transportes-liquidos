<?php

namespace App\Modules\AdministrativeAudit\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read existing canonical receipts, never their duplicate child records or private payloads. */
final class AdministrativeAudit
{
    public const MODULES = ['fleet', 'transport', 'workshop', 'inventory', 'purchasing', 'billing', 'expenses', 'alerts'];

    private const SOURCES = [
        'documents' => ['fleet', 'documents', "'document_registered'", 'uploaded_by_id', 'registered_at', "'document'", 'id'],
        'requests' => ['transport', 'transport_requests', "'request_registered'", 'registered_by_id', 'registered_at', "'request'", 'id'],
        'trips' => ['transport', 'trips', "'trip_registered'", 'registered_by_id', 'registered_at', "'trip'", 'id'],
        'changes' => ['transport', 'transport_changes', 'action', 'actor_id', 'registered_at', "CASE WHEN trip_id IS NULL THEN 'request' ELSE 'trip' END", 'coalesce(trip_id,transport_request_id)'],
        'authorizations' => ['transport', 'transport_authorization_requests', "'authorization_requested'", 'requested_by_id', 'registered_at', "CASE WHEN trip_id IS NULL THEN 'request' ELSE 'trip' END", 'coalesce(trip_id,transport_request_id)'],
        'decisions' => ['transport', 'transport_authorization_decisions', "'authorization_' || decision", 'decided_by_id', 'registered_at', "'authorization_request'", 'authorization_request_id'],
        'assignments' => ['transport', 'transport_assignment_operations', 'action', 'actor_id', 'registered_at', "'trip'", 'trip_id'],
        'workshop' => ['workshop', 'workshop_operations', 'operation_type', 'actor_id', 'registered_at', "CASE WHEN work_order_id IS NOT NULL THEN 'order' WHEN admission_id IS NOT NULL THEN 'admission' WHEN report_id IS NOT NULL THEN 'report' ELSE 'asset' END", 'coalesce(work_order_id,admission_id,report_id,fleet_asset_id)'],
        'inventory' => ['inventory', 'inventory_operations', "split_part(scope,':',1)", 'actor_id', 'registered_at', "'operation'", 'id'],
        'purchasing' => ['purchasing', 'purchasing_operations', 'scope', 'actor_id', 'registered_at', "'purchase_request'", 'purchase_request_id'],
        'fulfillment' => ['purchasing', 'purchase_fulfillment_operations', 'scope', 'actor_id', 'registered_at', "CASE WHEN purchase_receipt_id IS NULL THEN 'purchase_order' ELSE 'receipt' END", 'coalesce(purchase_receipt_id,purchase_order_id)'],
        'billing' => ['billing', 'billing_operations', "split_part(scope,':',1)", 'actor_id', 'registered_at', "CASE WHEN invoice_id IS NOT NULL THEN 'invoice' WHEN payment_id IS NOT NULL THEN 'payment' WHEN amount_id IS NOT NULL THEN 'amount' ELSE 'decision' END", 'coalesce(invoice_id,payment_id,amount_id,decision_id)'],
        'expenses' => ['expenses', 'expense_operations', "split_part(scope,':',1)", 'actor_id', 'registered_at', "CASE WHEN revision_id IS NOT NULL THEN 'revision' WHEN decision_id IS NOT NULL THEN 'decision' ELSE 'receipt' END", 'coalesce(revision_id,decision_id,receipt_id)'],
        'reviews' => ['alerts', 'document_alert_reviews', "'reviewed'", 'reviewed_by_id', 'reviewed_at', "'document'", 'document_id'],
    ];

    // Only scalar operational fields actually recorded in the original snapshots.
    // No free text, arbitrary nested metadata, filenames, credentials or private storage references.
    private const SAFE_CHANGES = ['planning_status', 'execution_status', 'administrative_status', 'scheduled_start_at', 'scheduled_end_at', 'started_at', 'officially_started_at', 'officially_completed_at', 'completed_at', 'cancelled_at', 'closed_at', 'version', 'product_id', 'liquid_product_id', 'customer_id', 'route_id', 'requested_liters', 'planned_liters', 'priority', 'status', 'kind', 'fleet_asset_id'];

    public function consult(array $filters): array
    {
        $root = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($filters, $root): array {
            if ($root) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $union = null;
            foreach (self::SOURCES as $source => [$module, $table, $operation, $actor, $date, $type, $record]) {
                if (isset($filters['module']) && $filters['module'] !== $module) {
                    continue;
                }
                $query = DB::table($table)->selectRaw("?::text as source, id, ?::text as module, $operation as operation, $actor as actor_id, $date as registered_at, $type as record_type, $record as record_id", [$source, $module]);
                if (isset($filters['from'])) {
                    $query->where($date, '>=', CarbonImmutable::parse($filters['from'], 'America/Costa_Rica')->startOfDay()->utc());
                }
                if (isset($filters['to'])) {
                    $query->where($date, '<', CarbonImmutable::parse($filters['to'], 'America/Costa_Rica')->addDay()->startOfDay()->utc());
                }
                if (isset($filters['actor_id'])) {
                    $query->where($actor, $filters['actor_id']);
                }
                if (isset($filters['operation'])) {
                    $query->whereRaw("($operation) = ?", [$filters['operation']]);
                }
                if (isset($filters['source'])) {
                    if ($source !== $filters['source']) {
                        continue;
                    }
                    $query->where('id', $filters['id']);
                }
                $union === null ? $union = $query : $union->unionAll($query);
            }
            abort_if($union === null, 404);
            $page = DB::query()->fromSub($union, 'events')->join('users as u', 'u.id', '=', 'events.actor_id')
                ->select('events.*', 'u.name as actor_name')->orderByDesc('registered_at')->orderBy('source')->orderBy('events.id')
                ->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

            return ['rows' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'timezone' => 'America/Costa_Rica'];
        });
    }

    public function detail(string $source, string $id): array
    {
        abort_unless(isset(self::SOURCES[$source]), 404);
        $event = $this->consult(['source' => $source, 'id' => $id])['rows'][0] ?? null;
        abort_if($event === null, 404);
        $changes = ['before' => null, 'after' => null];
        if (in_array($source, ['changes', 'workshop'], true)) {
            $row = DB::table(self::SOURCES[$source][1])->where('id', $id)->first(['before_values', 'after_values']);
            foreach (['before', 'after'] as $key) {
                $values = json_decode($row->{$key.'_values'} ?? 'null', true);
                if (is_array($values)) {
                    $changes[$key] = (object) array_filter(array_intersect_key($values, array_flip(self::SAFE_CHANGES)), fn ($value) => $value === null || is_scalar($value));
                }
            }
        }

        return ['event' => $event, ...$changes];
    }
}
