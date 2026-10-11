<?php

namespace App\Providers;

use App\Events\OrderCancelled;
use App\Events\OrderFilled;
use App\Events\OrderRejected;
use App\Events\OrderStateChanged;
use App\Listeners\InvalidateMarketSummary;
use App\Listeners\WriteOrderAuditLog;
use App\Models\Order;
use App\Policies\OrderPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);

        foreach ([OrderStateChanged::class, OrderFilled::class, OrderRejected::class, OrderCancelled::class] as $event) {
            Event::listen($event, WriteOrderAuditLog::class);
            Event::listen($event, InvalidateMarketSummary::class);
        }
    }
}
