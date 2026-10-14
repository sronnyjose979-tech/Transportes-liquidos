<?php

namespace App\Modules\BillingAndPayments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BillingAndPayments\Application\Money;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Administrative eligibility follows approved amounts and official completion. */
class BillingReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'customer_id' => ['sometimes', 'uuid'], 'execution_status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])], 'pending_only' => 'sometimes|boolean']);
        $query = Trip::query()->with('transportRequest.customer');
        if (isset($data['customer_id'])) {
            $query->whereHas('transportRequest', fn ($q) => $q->where('customer_id', $data['customer_id']));
        }
        if (isset($data['execution_status'])) {
            $query->where('execution_status', $data['execution_status']);
        }
        if (($data['pending_only'] ?? false)) {
            $query->where('execution_status', 'completed')->whereNotNull('officially_completed_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('billing_invoice_items')->whereColumn('trip_id', 'trips.id'))->whereExists(fn ($q) => $q->selectRaw('1')->from('billing_trip_amounts as amount')->join('billing_amount_decisions as decision', 'decision.amount_id', '=', 'amount.id')->whereColumn('amount.trip_id', 'trips.id')->where('decision.decision', 'approved'));
        }
        $page = $query->orderByDesc('registered_at')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $ids = $page->getCollection()->pluck('id');
        $amounts = DB::table('billing_trip_amounts as a')->leftJoin('billing_amount_decisions as d', 'd.amount_id', '=', 'a.id')->whereIn('a.trip_id', $ids)->select('a.*', 'd.id as decision_id', 'd.decision', 'd.reason as decision_reason', 'd.decided_by_id', 'd.decided_at')->orderBy('a.registered_at')->orderBy('a.id')->get()->groupBy('trip_id');
        $invoices = DB::table('billing_invoice_items')->whereIn('trip_id', $ids)->pluck('invoice_id', 'trip_id');
        $rows = $page->getCollection()->map(function (Trip $trip) use ($amounts, $invoices): array {
            $proposals = $amounts->get($trip->id, collect());
            $approved = $proposals->first(function ($candidate) use ($proposals): bool {
                if ($candidate->decision !== 'approved') {
                    return false;
                }
                $successor = $proposals->firstWhere('supersedes_id', $candidate->id);
                while ($successor) {
                    if ($successor->decision === 'approved') {
                        return false;
                    }
                    $successor = $proposals->firstWhere('supersedes_id', $successor->id);
                }

                return true;
            });
            $invoice = $invoices->get($trip->id);

            return ['id' => $trip->id, 'transport_request_id' => $trip->transport_request_id,
                'customer' => ['id' => $trip->transportRequest->customer->id, 'name' => $trip->transportRequest->customer->name],
                'product' => $trip->product_name_snapshot, 'execution_status' => $trip->execution_status, 'administrative_status' => $trip->administrative_status,
                'officially_completed_at' => $trip->officially_completed_at, 'billing_eligibility' => $trip->execution_status === 'completed' && $trip->officially_completed_at !== null && $approved !== null && $invoice === null,
                'invoice_id' => $invoice, 'approved_price' => $approved ? ['id' => $approved->id, 'amount' => Money::format(Money::minor($approved->amount)), 'currency' => $approved->currency] : null,
                'price_proposals' => $proposals->map(fn ($a) => ['id' => $a->id, 'amount' => Money::format(Money::minor($a->amount)), 'currency' => $a->currency, 'reason' => $a->reason, 'supersedes_id' => $a->supersedes_id, 'requested_by_id' => $a->requested_by_id, 'registered_at' => $a->registered_at, 'decision' => $a->decision_id ? ['id' => $a->decision_id, 'decision' => $a->decision, 'reason' => $a->decision_reason, 'decided_by_id' => $a->decided_by_id, 'decided_at' => $a->decided_at] : null])];
        });

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'billing_enabled' => true])->header('Cache-Control', 'private, no-store');
    }
}
