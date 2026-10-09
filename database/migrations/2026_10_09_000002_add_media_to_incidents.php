<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram file_ids only: [{"type":"photo|video","file_id":"..."}]. Never shown on the website.
        Schema::table('incidents', function (Blueprint $table) {
            $table->json('media')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('media');
        });
    }
};
