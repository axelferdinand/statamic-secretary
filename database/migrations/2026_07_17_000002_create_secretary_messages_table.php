<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secretary_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')
                ->constrained('secretary_conversations')
                ->cascadeOnDelete();
            $table->string('direction', 20);
            $table->string('channel', 20);
            $table->string('role', 20);
            $table->text('body');
            $table->string('provider_message_id')->nullable()->unique();
            $table->ulid('reply_to_message_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretary_messages');
    }
};
