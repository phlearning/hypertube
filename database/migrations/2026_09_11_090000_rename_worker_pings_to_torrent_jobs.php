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
        Schema::rename('worker_pings', 'torrent_jobs');

        Schema::table('torrent_jobs', function (Blueprint $table) {
            $table->string('type')->default('ping')->after('job_id');
            $table->string('title', 500)->nullable()->after('type');
            $table->string('torrent_url', 2048)->nullable()->after('status');
            $table->unsignedBigInteger('downloaded_bytes')->default(0)->after('torrent_url');
            $table->unsignedBigInteger('total_bytes')->nullable()->after('downloaded_bytes');
            $table->boolean('is_complete')->default(false)->after('total_bytes');
            $table->string('file_path', 2048)->nullable()->after('is_complete');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('torrent_jobs', function (Blueprint $table) {
            $table->dropColumn(['type', 'title', 'torrent_url', 'downloaded_bytes', 'total_bytes', 'is_complete', 'file_path']);
        });

        Schema::rename('torrent_jobs', 'worker_pings');
    }
};
