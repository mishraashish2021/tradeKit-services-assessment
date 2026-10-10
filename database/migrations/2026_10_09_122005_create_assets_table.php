
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20)->unique();
            $table->string('name');

            $table->unsignedTinyInteger('price_precision');
            $table->unsignedTinyInteger('quantity_precision');

            $table->boolean('is_tradable')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
