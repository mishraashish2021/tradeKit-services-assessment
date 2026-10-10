<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function show(): JsonResponse
    {
        $wallets = Wallet::query()
            ->where('user_id', auth()->id())
            ->get([
                'id',
                'currency',
                'available_balance',
                'reserved_balance',
            ]);

        return response()->json([
            'data' => $wallets,
        ]);
    }
}
