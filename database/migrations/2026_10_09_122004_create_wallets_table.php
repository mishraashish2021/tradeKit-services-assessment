
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('currency', 3)->default('INR');

            $table->decimal('available_balance', 20, 8)->default(0);
            $table->decimal('reserved_balance', 20, 8)->default(0);

            $table->timestamps();

            $table->unique(
                ['user_id', 'currency'],
                'wallets_user_currency_unique'
            );
        });

        DB::statement(
            'ALTER TABLE wallets ADD CONSTRAINT chk_wallets_available_nonnegative CHECK (available_balance >= 0)'
        );

        DB::statement(
            'ALTER TABLE wallets ADD CONSTRAINT chk_wallets_reserved_nonnegative CHECK (reserved_balance >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
