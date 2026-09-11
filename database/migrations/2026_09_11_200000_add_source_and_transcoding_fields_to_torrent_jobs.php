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
            $table->string('source')->nullable()->after('torrent_url');
            $table->string('playback_path', 2048)->nullable()->after('file_path');
            $table->string('transcode_status')->nullable()->after('playback_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('torrent_jobs', function (Blueprint $table) {
            $table->dropColumn(['source', 'playback_path', 'transcode_status']);
        });
    }
};
