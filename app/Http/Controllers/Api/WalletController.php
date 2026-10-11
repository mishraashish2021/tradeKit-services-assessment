<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Http\Resources\WalletResource;
use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $wallets = Wallet::query()->where('user_id', $request->user()->id)->get(['id', 'currency', 'available_balance', 'reserved_balance']);
        $positions = Position::query()->where('user_id', $request->user()->id)
            ->with('asset:id,symbol,name')->get();

        return response()->json(['data' => ['wallets' => WalletResource::collection($wallets)->resolve($request), 'positions' => PositionResource::collection($positions)->resolve($request)]]);
    }
}
