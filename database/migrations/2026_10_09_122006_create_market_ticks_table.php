
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_ticks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('asset_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('price', 20, 8);
            $table->timestamp('recorded_at', 6);

            $table->index(
                ['asset_id', 'recorded_at'],
                'market_ticks_asset_recorded_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_ticks');
    }
};
