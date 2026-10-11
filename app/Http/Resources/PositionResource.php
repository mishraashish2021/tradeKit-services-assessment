<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Position */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'asset_id' => $this->asset_id,
            'symbol' => $this->asset?->symbol,
            'name' => $this->asset?->name,
            'quantity' => $this->quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'available_quantity' => bcsub(
                $this->quantity,
                $this->reserved_quantity,
                8
            ),
            'average_cost' => $this->average_cost,
        ];
    }
}
