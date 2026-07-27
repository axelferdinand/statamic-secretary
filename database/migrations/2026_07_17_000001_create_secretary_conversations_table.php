<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secretary_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('channel', 20);
            $table->string('external_thread_id')->nullable();
            $table->string('user_id')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('status', 20)->default('open')->index();
            $table->string('openai_response_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretary_conversations');
    }
};
