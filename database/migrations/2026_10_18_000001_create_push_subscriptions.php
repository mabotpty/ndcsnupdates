<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('endpoint_hash', 64)->unique(); // sha256 of the endpoint, so it can be indexed
            $table->text('endpoint');
            $table->string('p256dh');
            $table->string('auth');
            $table->json('categories');                    // which kinds of alert this device wants
            $table->unsignedTinyInteger('min_weather_level')->default(0); // 0 = every weather warning
            $table->string('user_agent', 255)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
