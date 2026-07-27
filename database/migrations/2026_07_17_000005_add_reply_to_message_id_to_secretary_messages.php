<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('secretary_messages', 'reply_to_message_id')) {
            return;
        }

        Schema::table('secretary_messages', function (Blueprint $table): void {
            $table->ulid('reply_to_message_id')->nullable()->unique()->after('provider_message_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('secretary_messages', 'reply_to_message_id')) {
            return;
        }

        Schema::table('secretary_messages', function (Blueprint $table): void {
            $table->dropUnique(['reply_to_message_id']);
            $table->dropColumn('reply_to_message_id');
        });
    }
};
