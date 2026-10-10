
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->decimal('fee', 20, 8)->default(0);

            $table->timestamp('executed_at', 6);

            $table->unique('order_id', 'executions_order_unique');
            $table->index('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
