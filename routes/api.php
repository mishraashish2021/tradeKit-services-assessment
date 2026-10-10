<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MarketSummaryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('v1/login', [AuthController::class, 'login']);
Route::post('v1/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('v1/orders', [OrderController::class, 'store']);
    Route::get('v1/orders', [OrderController::class, 'index']);
    Route::get('v1/orders/{order}', [OrderController::class, 'show']);

    Route::get('v1/wallet', [WalletController::class, 'show']);

    Route::delete('v1/orders/{order}', [OrderController::class, 'destroy']);
});

// Public market summary endpoint
Route::get('v1/market/summary', [MarketSummaryController::class, 'index']);
