<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment('Keep your code simple, safe, and testable.');
})->purpose('Display an engineering reminder');

Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
