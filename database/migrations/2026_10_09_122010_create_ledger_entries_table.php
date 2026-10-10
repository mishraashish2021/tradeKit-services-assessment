
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->string('type', 40);

            // Signed changes to each wallet balance bucket.
            $table->decimal('amount', 20, 8);
            $table->decimal('reserved_amount', 20, 8)->default(0);

            // Balance snapshots after applying this entry.
            $table->decimal('balance_after', 20, 8);
            $table->decimal('reserved_balance_after', 20, 8);

            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(
                ['wallet_id', 'id'],
                'ledger_wallet_cursor_idx'
            );

            $table->index(
                ['order_id', 'type'],
                'ledger_order_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
