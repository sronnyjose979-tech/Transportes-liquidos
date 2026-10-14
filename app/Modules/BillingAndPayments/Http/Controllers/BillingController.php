<?php

namespace App\Modules\BillingAndPayments\Http\Controllers;

use App\Modules\BillingAndPayments\Application\BillingPayments;
use App\Modules\BillingAndPayments\Http\Requests\BillingWriteRequest;
use App\Modules\BillingAndPayments\Http\Resources\AdministrativeInvoiceResource;
use App\Modules\BillingAndPayments\Models\AdministrativeInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BillingController
{
    public function propose(BillingWriteRequest $request, string $trip, BillingPayments $service): JsonResponse
    {
        return response()->json(['data' => $service->propose($request->user(), $trip, $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function decide(BillingWriteRequest $request, string $id, BillingPayments $service): JsonResponse
    {
        return response()->json(['data' => $service->decide($request->user(), $id, $request->validated('request_key'), $request->safe()->except(['request_key']))]);
    }

    public function store(BillingWriteRequest $request, BillingPayments $service): JsonResponse
    {
        return response()->json(['data' => $service->invoice($request->user(), $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function pay(BillingWriteRequest $request, string $id, BillingPayments $service): JsonResponse
    {
        return response()->json(['data' => $service->payment($request->user(), $id, $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'customer_id' => 'sometimes|uuid', 'currency' => ['sometimes', Rule::in(['CRC', 'USD'])], 'status' => ['sometimes', Rule::in(['pending', 'partially_paid', 'paid'])], 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from']);
        $query = AdministrativeInvoice::query()->with(['items', 'payments']);
        foreach (['customer_id', 'currency'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($data[$field])) {
                $query->whereRaw("(billing_invoices.registered_at AT TIME ZONE 'America/Costa_Rica')::date {$operator} ?::date", [$data[$field]]);
            }
        }
        $paid = 'COALESCE((SELECT sum(amount) FROM billing_payments WHERE invoice_id=billing_invoices.id),0)';
        $total = '(SELECT sum(amount) FROM billing_invoice_items WHERE invoice_id=billing_invoices.id)';
        if (($data['status'] ?? null) === 'pending') {
            $query->whereRaw($paid.'=0 AND '.$total.'>0');
        } elseif (($data['status'] ?? null) === 'paid') {
            $query->whereRaw($paid.'='.$total);
        } elseif (($data['status'] ?? null) === 'partially_paid') {
            $query->whereRaw($paid.'>0 AND '.$paid.'<'.$total);
        }

        return AdministrativeInvoiceResource::collection($query->orderByDesc('registered_at')->orderBy('id')->paginate($data['per_page'] ?? 25));
    }

    public function show(string $id): AdministrativeInvoiceResource
    {
        return new AdministrativeInvoiceResource(AdministrativeInvoice::query()->with(['items', 'payments'])->findOrFail($id));
    }
}
