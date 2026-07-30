<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('secretary_change_sets', 'review')) {
            return;
        }

        Schema::table('secretary_change_sets', function (Blueprint $table): void {
            $table->json('review')->nullable()->after('after');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('secretary_change_sets', 'review')) {
            return;
        }

        Schema::table('secretary_change_sets', function (Blueprint $table): void {
            $table->dropColumn('review');
        });
    }
};
