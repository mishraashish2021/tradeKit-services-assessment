<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OrderStateChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly int $orderId,
        public readonly int $actorId,
        public readonly string $action,
        public readonly array $before,
        public readonly array $after,
        public readonly ?string $ipAddress = null,
    ) {}
}
