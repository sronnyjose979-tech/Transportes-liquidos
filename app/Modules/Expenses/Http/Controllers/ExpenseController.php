<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Modules\Expenses\Application\ExpenseAdministration;
use App\Modules\Expenses\Http\Requests\ExpenseWriteRequest;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Infrastructure\PrivateReceiptStorage;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ExpenseController
{
    private function visible(Request $request)
    {
        $query = Expense::query()->with(['revisions.decision', 'receipts']);
        if (! ExpenseAdministration::office($request->user())) {
            $query->where('created_by_id', $request->user()->id);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $data = $request->validate(['mine' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'trip_id' => 'sometimes|uuid', 'currency' => ['sometimes', Rule::in(['CRC', 'USD'])], 'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])], 'search' => 'sometimes|string|max:255', 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from']);
        $query = $this->visible($request);
        if ($data['mine'] ?? false) {
            $query->where('created_by_id', $request->user()->id);
        }
        if (isset($data['trip_id'])) {
            $query->where('trip_id', $data['trip_id']);
        }
        $query->whereHas('revisions', function ($revision) use ($data): void {
            $revision->whereRaw('NOT EXISTS(SELECT 1 FROM expense_revisions n WHERE n.supersedes_id=expense_revisions.id)');
            if (isset($data['currency'])) {
                $revision->where('currency', $data['currency']);
            }
            if (isset($data['search'])) {
                $revision->where(fn ($q) => $q->where('concept', 'ilike', '%'.$data['search'].'%')->orWhere('category', 'ilike', '%'.$data['search'].'%'));
            }
            foreach (['from' => '>=', 'to' => '<='] as $field => $op) {
                if (isset($data[$field])) {
                    $revision->whereRaw("(occurred_at AT TIME ZONE 'America/Costa_Rica')::date {$op} ?::date", [$data[$field]]);
                }
            }
            if (($data['status'] ?? null) === 'pending') {
                $revision->whereDoesntHave('decision');
            } elseif (isset($data['status'])) {
                $revision->whereHas('decision', fn ($q) => $q->where('decision', $data['status']));
            }
        });

        return ExpenseResource::collection($query->orderByDesc('registered_at')->orderBy('id')->paginate($data['per_page'] ?? 25));
    }

    public function show(Request $request, string $id): ExpenseResource
    {
        return new ExpenseResource($this->visible($request)->findOrFail($id));
    }

    public function store(ExpenseWriteRequest $request, ExpenseAdministration $service)
    {
        return response()->json(['data' => $service->create($request->user(), $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function correct(ExpenseWriteRequest $request, string $id, ExpenseAdministration $service)
    {
        abort_unless(ExpenseAdministration::office($request->user()), 403);

        return response()->json(['data' => $service->correct($request->user(), $id, $request->validated('request_key'), $request->safe()->except(['request_key']))], 201);
    }

    public function decide(ExpenseWriteRequest $request, string $id, ExpenseAdministration $service)
    {
        return response()->json(['data' => $service->decide($request->user(), $id, $request->validated('request_key'), $request->safe()->except(['request_key']))]);
    }

    public function upload(ExpenseWriteRequest $request, string $id, ExpenseAdministration $service, PrivateReceiptStorage $storage)
    {
        $record = $this->visible($request)->findOrFail($id);
        $file = $request->file('file');
        $data = ['sha256' => hash_file('sha256', $file->getPathname()), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'extension' => strtolower($file->getClientOriginalExtension())];
        $result = $storage->register($file, fn (string $receipt, string $key) => $service->receipt($request->user(), $id, $request->validated('request_key'), $data, function () use ($request, $id, $receipt, $key, $data): array {
            DB::table('expense_receipts')->insert(['id' => $receipt, 'expense_id' => $id, 'storage_key' => $key, 'mime_type' => $data['mime_type'], 'size_bytes' => $data['size_bytes'], 'sha256' => $data['sha256'], 'uploaded_by_id' => $request->user()->id]);

            return ['expense_id' => $id, 'receipt_id' => $receipt];
        }));

        return response()->json(['data' => $result], 201);
    }

    public function download(Request $request, string $receipt, PrivateReceiptStorage $storage)
    {
        $file = ExpenseReceipt::query()->findOrFail($receipt);
        $this->visible($request)->findOrFail($file->expense_id);

        return $storage->download($file);
    }
}
