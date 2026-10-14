<?php

namespace App\Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $decision = $this->decision;

        return [
            'id' => $this->id,
            'supplier' => ['id' => $this->supplier->id, 'name' => $this->supplier->name],
            'reason' => $this->reason,
            'requested_by' => ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name],
            'registered_at' => $this->registered_at->toIso8601String(),
            'items' => $this->items->map(fn ($item): array => ['id' => $item->id, 'spare_part_id' => $item->spare_part_id,
                'workshop_material_need_id' => $item->workshop_material_need_id, 'code' => $item->code_snapshot,
                'description' => $item->description_snapshot, 'unit' => $item->unit_snapshot, 'quantity' => $item->quantity])->all(),
            'decision' => $decision === null ? null : ['id' => $decision->id, 'decision' => $decision->decision,
                'reason' => $decision->reason, 'decided_by' => ['id' => $decision->decidedBy->id, 'name' => $decision->decidedBy->name],
                'registered_at' => $decision->registered_at->toIso8601String()],
        ];
    }
}
