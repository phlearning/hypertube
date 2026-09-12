<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('torrent_jobs', function (Blueprint $table) {
            $table->unsignedInteger('seeders')->nullable()->after('source');
            $table->unsignedInteger('peers')->nullable()->after('seeders');
            $table->json('attempted_candidates')->nullable()->after('remaining_candidates');
            $table->json('media_info')->nullable()->after('transcode_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('torrent_jobs', function (Blueprint $table) {
            $table->dropColumn(['seeders', 'peers', 'attempted_candidates', 'media_info']);
        });
    }
};
