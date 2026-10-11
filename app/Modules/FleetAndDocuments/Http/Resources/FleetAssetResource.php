<?php

namespace App\Modules\FleetAndDocuments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'code' => $this->code, 'type' => $this->type, 'service_status' => $this->service_status];
        if ($this->type === 'tanker') {
            $compartments = $this->tanker->compartments;
            $hundredths = '0';
            foreach ($compartments as $compartment) {
                $hundredths = $this->addHundredths($hundredths, str_replace('.', '', $compartment->capacity_liters));
            }
            $data['has_physical_partitions'] = $this->tanker->has_physical_partitions;
            $hundredths = str_pad($hundredths, 3, '0', STR_PAD_LEFT);
            $data['total_capacity_liters'] = substr($hundredths, 0, -2).'.'.substr($hundredths, -2);
            $data['compartments'] = $compartments->map(fn ($compartment): array => $compartment->only(['id', 'tanker_id', 'code', 'capacity_liters', 'is_active']))->all();
        }

        return $data;
    }

    private function addHundredths(string $left, string $right): string
    {
        // Sum exact decimal strings without floats or a limit on the aggregate total.
        $length = max(strlen($left), strlen($right));
        $left = str_pad($left, $length, '0', STR_PAD_LEFT);
        $right = str_pad($right, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $sum = '';
        for ($index = $length - 1; $index >= 0; $index--) {
            $digit = (int) $left[$index] + (int) $right[$index] + $carry;
            $sum = ($digit % 10).$sum;
            $carry = intdiv($digit, 10);
        }

        return ltrim(($carry ? '1' : '').$sum, '0') ?: '0';
    }
}
