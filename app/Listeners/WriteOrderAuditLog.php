<?php

namespace App\Listeners;

use App\Events\OrderStateChanged;
use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class WriteOrderAuditLog implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public function handle(OrderStateChanged $event): void
    {
        AuditLog::create([
            'actor_id' => $event->actorId,
            'action' => $event->action,
            'subject_type' => 'order',
            'subject_id' => (string) $event->orderId,
            'context' => ['before' => $event->before, 'after' => $event->after],
            'ip_address' => $event->ipAddress,
        ]);
    }
}
