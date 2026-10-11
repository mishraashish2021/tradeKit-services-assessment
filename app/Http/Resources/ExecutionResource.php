<?php

namespace App\Http\Resources;

use App\Models\Execution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Execution */
class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'fee' => $this->fee,
            'executed_at' => Carbon::parse($this->executed_at)->toISOString(),
        ];
    }
}
