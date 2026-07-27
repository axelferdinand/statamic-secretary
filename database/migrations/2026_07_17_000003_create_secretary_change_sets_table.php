<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secretary_change_sets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')
                ->constrained('secretary_conversations')
                ->cascadeOnDelete();
            $table->foreignUlid('proposed_by_message_id')
                ->nullable()
                ->constrained('secretary_messages')
                ->nullOnDelete();
            $table->string('status', 24)->default('proposed')->index();
            $table->string('operation', 20);
            $table->string('resource_type', 30)->default('entry');
            $table->string('resource_id')->nullable()->index();
            $table->string('collection')->nullable()->index();
            $table->string('site')->nullable();
            $table->string('blueprint')->nullable();
            $table->string('slug')->nullable();
            $table->string('parent_id')->nullable();
            $table->string('base_fingerprint', 64)->nullable();
            $table->string('draft_fingerprint', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('patch')->nullable();
            $table->json('after')->nullable();
            $table->text('summary')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretary_change_sets');
    }
};
