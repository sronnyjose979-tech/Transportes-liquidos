<?php

namespace App\Modules\BillingAndPayments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TransportAndDispatch\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Read-only preparation: a completed trip is not automatically billable. */
class BillingReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'customer_id' => ['sometimes', 'uuid'], 'execution_status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])]]);
        $query = Trip::query()->with('transportRequest.customer');
        if (isset($data['customer_id'])) {
            $query->whereHas('transportRequest', fn ($q) => $q->where('customer_id', $data['customer_id']));
        }
        if (isset($data['execution_status'])) {
            $query->where('execution_status', $data['execution_status']);
        }
        $page = $query->orderByDesc('registered_at')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows = $page->getCollection()->map(fn (Trip $trip) => ['id' => $trip->id, 'transport_request_id' => $trip->transport_request_id,
            'customer' => ['id' => $trip->transportRequest->customer->id, 'name' => $trip->transportRequest->customer->name],
            'product' => $trip->product_name_snapshot, 'execution_status' => $trip->execution_status, 'administrative_status' => $trip->administrative_status,
            'officially_completed_at' => $trip->officially_completed_at, 'billing_eligibility' => null]);

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'billing_enabled' => false])->header('Cache-Control', 'private, no-store');
    }
}
