<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'id'], 'orders_user_status_cursor_idx');
            $table->index(['user_id', 'asset_id', 'id'], 'orders_user_asset_cursor_idx');
        });

        // MySQL triggers make the append-only ledger invariant enforceable below Eloquent.
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only'");
            DB::unprepared("CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_status_cursor_idx');
            $table->dropIndex('orders_user_asset_cursor_idx');
        });
    }
};
