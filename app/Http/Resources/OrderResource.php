<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'asset' => [
                'id' => $this->asset?->id,
                'symbol' => $this->asset?->symbol,
                'name' => $this->asset?->name,
            ],
            'side' => $this->side->value,
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'limit_price' => $this->limit_price,
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'executions' => ExecutionResource::collection(
                $this->whenLoaded('executions')
            ),
        ];
    }
}
