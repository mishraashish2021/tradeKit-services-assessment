<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MarketSummaryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('v1/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('v1/logout', [AuthController::class, 'logout']);
    Route::post('v1/orders', [OrderController::class, 'store'])->middleware('throttle:30,1');
    Route::get('v1/orders', [OrderController::class, 'index']);
    Route::get('v1/orders/{order}', [OrderController::class, 'show']);
    Route::delete('v1/orders/{order}', [OrderController::class, 'destroy']);
    Route::get('v1/wallet', [WalletController::class, 'show']);
    Route::get('v1/market/summary', [MarketSummaryController::class, 'index']);
});
