<?php

namespace App\Modules\Expenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $superseded = $this->revisions->pluck('supersedes_id')->filter()->all();
        $current = $this->revisions->first(fn ($revision) => ! in_array($revision->id, $superseded, true));

        return ['id' => $this->id, 'trip_id' => $this->trip_id, 'created_by_id' => $this->created_by_id, 'registered_at' => $this->registered_at, 'current_revision_id' => $current->id, 'concept' => $current->concept, 'category' => $current->category, 'amount' => $current->amount, 'currency' => $current->currency, 'occurred_at' => $current->occurred_at, 'description' => $current->description, 'status' => $current->decision?->decision ?? 'pending', 'versions' => $this->revisions->map(fn ($v) => $v->attributesToArray()), 'decisions' => $this->revisions->map(fn ($v) => $v->decision)->filter()->values(), 'receipts' => $this->receipts];
    }
}
