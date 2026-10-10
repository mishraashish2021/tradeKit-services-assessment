
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('asset_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('side', 4);
            $table->string('type', 6);
            $table->decimal('quantity', 20, 8);
            $table->decimal('limit_price', 20, 8)->nullable();

            $table->string('status', 12)->default('PENDING');

            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);

            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['user_id', 'idempotency_key'],
                'orders_user_idempotency_unique'
            );

            $table->index(
                ['status', 'asset_id', 'side', 'type', 'limit_price'],
                'orders_matching_idx'
            );

            $table->index(
                ['user_id', 'id'],
                'orders_user_cursor_idx'
            );

            $table->index(
                ['status', 'expires_at'],
                'orders_expiration_idx'
            );
        });

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_side
            CHECK (side IN ('BUY', 'SELL'))
        ");

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_type
            CHECK (type IN ('LIMIT', 'MARKET'))
        ");

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_status
            CHECK (
                status IN (
                    'PENDING',
                    'PROCESSING',
                    'FILLED',
                    'CANCELLED',
                    'REJECTED',
                    'FAILED'
                )
            )
        ");

        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_quantity_positive
            CHECK (quantity > 0)
        ');

        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_limit_price_positive
            CHECK (limit_price IS NULL OR limit_price > 0)
        ');

    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
