
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->string('subject_id', 100)->nullable();

            $table->json('context')->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(
                ['subject_type', 'subject_id'],
                'audit_subject_idx'
            );

            $table->index(
                ['actor_id', 'created_at'],
                'audit_actor_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
