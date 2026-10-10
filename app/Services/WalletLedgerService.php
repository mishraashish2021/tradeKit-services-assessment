<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class WalletLedgerService
{
    /**
     * Reserve funds for an order.
     *
     * $amount must be a positive decimal string.
     */
    public function reserve(
        int $walletId,
        string $amount,
        ?Order $order = null
    ): LedgerEntry {
        if (! preg_match('/^\d+(\.\d{1,8})?$/', $amount)
            || bccomp($amount, '0', 8) <= 0) {
            throw new InvalidArgumentException(
                'Reservation amount must be a positive decimal with up to 8 decimal places.'
            );
        }

        return DB::transaction(function () use ($walletId, $amount, $order) {
            $wallet = Wallet::query()
                ->lockForUpdate()
                ->findOrFail($walletId);

            if (bccomp($wallet->available_balance, $amount, 8) < 0) {
                throw new RuntimeException('Insufficient available balance.');
            }

            $wallet->available_balance = bcsub(
                $wallet->available_balance,
                $amount,
                8
            );

            $wallet->reserved_balance = bcadd(
                $wallet->reserved_balance,
                $amount,
                8
            );

            $wallet->save();

            return LedgerEntry::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order?->id,
                'type' => 'RESERVATION',
                'amount' => '-'.$amount,
                'reserved_amount' => $amount,
                'balance_after' => $wallet->available_balance,
                'reserved_balance_after' => $wallet->reserved_balance,
            ]);
        });
    }

    public function release(
        int $walletId,
        string $amount,
        ?Order $order = null
    ): LedgerEntry {
        if (
            ! preg_match('/^\d+(\.\d{1,8})?$/', $amount)
            || bccomp($amount, '0', 8) <= 0
        ) {
            throw new InvalidArgumentException(
                'Release amount must be a positive decimal with up to 8 decimal places.'
            );
        }

        return DB::transaction(function () use ($walletId, $amount, $order) {
            $wallet = Wallet::query()
                ->lockForUpdate()
                ->findOrFail($walletId);

            if (bccomp($wallet->reserved_balance, $amount, 8) < 0) {
                throw new RuntimeException(
                    'Insufficient reserved balance to release.'
                );
            }

            $wallet->reserved_balance = bcsub(
                $wallet->reserved_balance,
                $amount,
                8
            );

            $wallet->available_balance = bcadd(
                $wallet->available_balance,
                $amount,
                8
            );

            $wallet->save();

            return LedgerEntry::create([
                'wallet_id' => $wallet->id,
                'order_id' => $order?->id,
                'type' => 'RELEASE',
                'amount' => $amount,
                'reserved_amount' => '-'.$amount,
                'balance_after' => $wallet->available_balance,
                'reserved_balance_after' => $wallet->reserved_balance,
            ]);
        });
    }
}
