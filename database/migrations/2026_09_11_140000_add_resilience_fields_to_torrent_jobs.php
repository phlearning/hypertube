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
            $table->string('info_hash', 40)->nullable()->after('torrent_url')->index();
            $table->json('remaining_candidates')->nullable()->after('info_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('torrent_jobs', function (Blueprint $table) {
            $table->dropColumn(['info_hash', 'remaining_candidates']);
        });
    }
};
