<?php

namespace App\Modules\AdministrativeReports\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read models over existing facts; no operational or financial writes. */
final class AdministrativeReports
{
    private function period(Builder $query, string $column, array $filters): Builder
    {
        if (isset($filters['from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['from'], 'America/Costa_Rica')->startOfDay()->utc());
        }
        if (isset($filters['to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['to'], 'America/Costa_Rica')->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    private function equal(Builder $query, array $filters, array $columns): Builder
    {
        foreach ($columns as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query;
    }

    public function consult(string $kind, array $filters): array
    {
        $root = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($kind, $filters, $root): array {
            if ($root) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            [$query, $summary, $note, $href] = $this->$kind($filters);
            $page = $query->orderByDesc('date')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

            return ['kind' => $kind, 'rows' => $page->items(), 'summary' => $summary,
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
                'note' => $note, 'module_href' => $href, 'timezone' => 'America/Costa_Rica'];
        });
    }

    private function transport(array $filters): array
    {
        $query = DB::table('trips as t')->join('transport_requests as r', 'r.id', '=', 't.transport_request_id')->join('customers as c', 'c.id', '=', 'r.customer_id');
        $this->period($query, 't.registered_at', $filters);
        $this->equal($query, $filters, ['customer_id' => 'r.customer_id', 'status' => 't.execution_status']);
        if (isset($filters['fleet_asset_id'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('trip_asset_assignments as a')->whereColumn('a.trip_id', 't.id')->where('a.fleet_asset_id', $filters['fleet_asset_id']));
        }
        $summary = (clone $query)->select('t.execution_status as status', 'r.customer_id', 'c.name as customer')->selectRaw('count(*)::integer as count')->groupBy('t.execution_status', 'r.customer_id', 'c.name')->orderBy('c.name')->orderBy('t.execution_status')->get();
        $query->select('t.id', 't.registered_at as date', 'c.name as customer', 't.execution_status as status', 't.planning_status', 't.administrative_status', 't.product_name_snapshot as product');

        return [$query, $summary, 'Período según fecha de registro del viaje. Estados actuales. Vehículo: cualquier asignación histórica; cada viaje se cuenta una vez.', '/transporte'];
    }

    private function billing(array $filters): array
    {
        $items = DB::table('billing_invoice_items')->select('invoice_id')->selectRaw('sum(amount) as total')->groupBy('invoice_id');
        $payments = DB::table('billing_payments')->select('invoice_id')->selectRaw('sum(amount) as paid')->groupBy('invoice_id');
        $base = DB::table('billing_invoices as i')->joinSub($items, 'items', 'items.invoice_id', '=', 'i.id')->leftJoinSub($payments, 'p', 'p.invoice_id', '=', 'i.id')
            ->select('i.id', 'i.customer_id', 'i.customer_name_snapshot as customer', 'i.currency', 'i.registered_at as date')
            ->selectRaw("items.total, COALESCE(p.paid,0) as paid, items.total-COALESCE(p.paid,0) as balance, CASE WHEN items.total=COALESCE(p.paid,0) THEN 'paid' WHEN COALESCE(p.paid,0)=0 THEN 'pending' ELSE 'partially_paid' END as status");
        $all = DB::query()->fromSub($base, 'b');
        $this->equal($all, $filters, ['customer_id' => 'b.customer_id', 'currency' => 'b.currency', 'status' => 'b.status']);
        $query = clone $all;
        $this->period($query, 'b.date', $filters);
        $cohort = (clone $query)->select('b.currency')->selectRaw('count(*)::integer as count, sum(b.total)::text as invoiced, sum(b.paid)::text as paid_to_date, sum(b.balance)::text as balance')->groupBy('b.currency')->orderBy('b.currency')->get();
        // Receipt dates are independent of invoice creation dates; payments have one invoice.
        $receipts = DB::table('billing_payments as receipt')->joinSub($all->select('b.id', 'b.currency'), 'invoice', 'invoice.id', '=', 'receipt.invoice_id');
        $this->period($receipts, 'receipt.paid_at', $filters);
        $received = $receipts->select('invoice.currency')->selectRaw('count(*)::integer as count, sum(receipt.amount)::text as received')->groupBy('invoice.currency')->orderBy('invoice.currency')->get();
        $query->select('b.id', 'b.date', 'b.customer', 'b.currency', 'b.status')->selectRaw('b.total::text as invoiced, b.paid::text as paid_to_date, b.balance::text as balance');

        return [$query, ['invoices' => $cohort, 'receipts' => $received], 'Facturas por fecha de registro; pagado y saldo actuales de esas facturas. Cobros del período por fecha del pago, incluso de facturas anteriores. Los filtros cliente, moneda y estado actual se aplican a ambos. Documentos administrativos internos.', '/facturacion'];
    }

    private function expenses(array $filters): array
    {
        $query = DB::table('expenses as e')->join('expense_revisions as v', 'v.expense_id', '=', 'e.id')->leftJoin('expense_decisions as d', 'd.revision_id', '=', 'v.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('expense_revisions as next')->whereColumn('next.supersedes_id', 'v.id'));
        $this->period($query, 'v.occurred_at', $filters);
        $this->equal($query, $filters, ['currency' => 'v.currency']);
        if (isset($filters['status'])) {
            $query->whereRaw("COALESCE(d.decision,'pending') = ?", [$filters['status']]);
        }
        $summary = (clone $query)->select('v.currency')->selectRaw("COALESCE(d.decision,'pending') as status, count(*)::integer as count, sum(v.amount)::text as amount")->groupBy('v.currency', 'd.decision')->orderBy('v.currency')->orderBy('d.decision')->get();
        $query->select('e.id', 'e.trip_id', 'v.occurred_at as date', 'v.concept', 'v.category', 'v.currency')->selectRaw("v.amount::text as amount, COALESCE(d.decision,'pending') as status");

        return [$query, $summary, 'Fecha del gasto en su versión actual. Una fila por gasto, sin sumar versiones sustituidas. Aprobado no significa pagado ni reembolsado.', '/gastos'];
    }

    private function workshop(array $filters): array
    {
        $activities = DB::table('workshop_activities as a')->join('workshop_scope_tasks as task', 'task.id', '=', 'a.task_id')->join('workshop_scopes as s', 's.id', '=', 'task.scope_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workshop_activities as correction')->whereColumn('correction.previous_activity_id', 'a.id'))
            ->select('s.work_order_id')->selectRaw('count(*)::integer as activity_count, max(a.occurred_at) as last_activity_at')->groupBy('s.work_order_id');
        $query = DB::table('workshop_work_orders as w')->join('fleet_assets as f', 'f.id', '=', 'w.fleet_asset_id')->leftJoinSub($activities, 'a', 'a.work_order_id', '=', 'w.id');
        $this->period($query, 'w.created_at', $filters);
        $this->equal($query, $filters, ['status' => 'w.status', 'fleet_asset_id' => 'w.fleet_asset_id']);
        $summary = (clone $query)->select('w.status')->selectRaw('count(*)::integer as count, COALESCE(sum(a.activity_count),0)::integer as activities')->groupBy('w.status')->orderBy('w.status')->get();
        $query->select('w.id', 'w.created_at as date', 'f.code as vehicle', 'w.status', 'w.description', 'w.priority', 'a.last_activity_at')->selectRaw('COALESCE(a.activity_count,0)::integer as activities');

        return [$query, $summary, 'Órdenes por fecha de creación y estado actual. Actividades registradas de esas órdenes durante toda su historia, contando solo la versión actual de cada actividad. No se calculan costos o tiempos no registrados.', '/taller'];
    }

    private function stock(array $filters): array
    {
        // Stock is current, not a backdated reconstruction; a period would misrepresent it.
        $movements = DB::table('inventory_movements')->select('spare_part_id')->selectRaw("sum(warehouse_delta)::text as available, sum(custody_delta)::text as custody, sum(consumed_delta)::text as consumed, bool_or(kind='initial') as initialized")->groupBy('spare_part_id');
        $query = DB::table('spare_parts as p')->leftJoinSub($movements, 'm', 'm.spare_part_id', '=', 'p.id');
        $this->equal($query, $filters, ['spare_part_id' => 'p.id']);
        $summary = (clone $query)->selectRaw('count(*)::integer as parts, count(m.spare_part_id)::integer as parts_with_movements')->first();
        $query->select('p.id', 'p.code', 'p.description', 'p.unit', 'm.available', 'm.custody', 'm.consumed', 'm.initialized')->selectRaw('NULL::timestamptz as date');

        return [$query, $summary, 'Existencias actuales; no se aplica período. Sin movimientos significa sin saldo registrado, no existencia física cero. Cantidades por repuesto/unidad; no se suman repuestos distintos ni se calcula valoración.', '/inventario'];
    }

    private function movements(array $filters): array
    {
        $query = DB::table('inventory_movements as m')->join('spare_parts as p', 'p.id', '=', 'm.spare_part_id');
        $this->period($query, 'm.occurred_at', $filters);
        $this->equal($query, $filters, ['status' => 'm.kind', 'spare_part_id' => 'm.spare_part_id']);
        $summary = (clone $query)->select('m.kind as status')->selectRaw('count(*)::integer as count')->groupBy('m.kind')->orderBy('m.kind')->get();
        $query->select('m.id', 'm.occurred_at as date', 'p.code', 'm.unit_snapshot as unit', 'm.kind as status')->selectRaw('m.warehouse_delta::text as warehouse_delta, m.custody_delta::text as custody_delta, m.consumed_delta::text as consumed_delta');

        return [$query, $summary, 'Movimientos confirmados por fecha efectiva. Incluye compensaciones sin eliminar el historial. Deltas por repuesto/unidad; no se calculan costos.', '/inventario'];
    }
}
