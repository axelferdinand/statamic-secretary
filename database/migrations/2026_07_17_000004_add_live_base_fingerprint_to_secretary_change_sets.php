<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secretary_change_sets', function (Blueprint $table): void {
            $table->string('live_base_fingerprint', 64)->nullable()->after('base_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('secretary_change_sets', function (Blueprint $table): void {
            $table->dropColumn('live_base_fingerprint');
        });
    }
};
