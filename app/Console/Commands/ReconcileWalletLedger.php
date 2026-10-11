<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileWalletLedger extends Command
{
    protected $signature = 'ledger:reconcile';

    protected $description = 'Compare wallet balances with append-only ledger totals';

    public function handle(): int
    {
        $mismatches = 0;
        foreach (Wallet::query()->orderBy('id')->cursor() as $wallet) {
            $totals = DB::table('ledger_entries')->where('wallet_id', $wallet->id)
                ->selectRaw('COALESCE(SUM(amount), 0) AS available_total')
                ->selectRaw('COALESCE(SUM(reserved_amount), 0) AS reserved_total')
                ->first();
            $available = bcadd((string) $totals->available_total, '0', 8);
            $reserved = bcadd((string) $totals->reserved_total, '0', 8);
            if (bccomp($available, (string) $wallet->available_balance, 8) !== 0 || bccomp($reserved, (string) $wallet->reserved_balance, 8) !== 0) {
                $mismatches++;
                $this->error("Wallet {$wallet->id} mismatch: ledger available={$available}, wallet available={$wallet->available_balance}; ledger reserved={$reserved}, wallet reserved={$wallet->reserved_balance}");
            }
        }
        if ($mismatches > 0) {
            $this->error("Reconciliation failed for {$mismatches} wallet(s).");

            return self::FAILURE;
        }
        $this->info('All wallet balances match their ledger totals.');

        return self::SUCCESS;
    }
}
