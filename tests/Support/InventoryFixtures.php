<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Models\SparePart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit synthetic physical counts, exclusively in isolated tests. */
final class InventoryFixtures
{
    public static function reconcile(User $administrator, SparePart $part, string $quantity): void
    {
        DB::transaction(function () use ($administrator, $part, $quantity): void {
            $ledger = app(InventoryLedger::class);
            $at = now()->toIso8601String();
            $correction = $ledger->correction($administrator, (string) Str::uuid(), [
                'spare_part_id' => $part->id, 'kind' => 'initial',
                'quantity' => $quantity, 'unit_snapshot' => $part->unit,
                'reason' => 'Conteo físico sintético explícito de la prueba', 'occurred_at' => $at,
            ]);
            $decision = $ledger->decide($administrator, $correction['correction_id'], (string) Str::uuid(), [
                'decision' => 'approved', 'reason' => 'Conciliación sintética autorizada', 'occurred_at' => $at,
            ]);
            $ledger->apply($administrator, $decision['decision_id'], (string) Str::uuid(), ['occurred_at' => $at]);
        });
    }
}
