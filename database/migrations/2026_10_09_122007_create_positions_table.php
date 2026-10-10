
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('asset_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('quantity', 20, 8)->default(0);
            $table->decimal('reserved_quantity', 20, 8)->default(0);
            $table->decimal('average_cost', 20, 8)->default(0);

            $table->timestamps();

            $table->unique(
                ['user_id', 'asset_id'],
                'positions_user_asset_unique'
            );
        });

        DB::statement(
            'ALTER TABLE positions ADD CONSTRAINT chk_positions_quantity_nonnegative CHECK (quantity >= 0)'
        );

        DB::statement(
            'ALTER TABLE positions ADD CONSTRAINT chk_positions_reserved_nonnegative CHECK (reserved_quantity >= 0)'
        );

        DB::statement(
            'ALTER TABLE positions ADD CONSTRAINT chk_positions_reserved_le_quantity CHECK (reserved_quantity <= quantity)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
