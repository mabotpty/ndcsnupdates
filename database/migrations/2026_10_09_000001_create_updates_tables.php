<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->unique();
            $table->string('telegram_link_code')->nullable();
            $table->timestamp('telegram_link_expires_at')->nullable();
        });

        Schema::create('alert_levels', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->primary();
            $table->string('name');
            $table->string('colour'); // green | yellow | orange | red
            $table->string('description');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('monitoring'); // warning | monitoring | resolved
            $table->timestamp('published_at')->useCurrent()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->string('source')->default('web'); // web | telegram
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('report_groups', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_group_id')->constrained()->cascadeOnDelete();
            $table->string('area')->nullable();
            $table->string('status')->default('ok'); // ok | warning | danger
            $table->text('lines'); // one bullet per line
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('source_name')->nullable();
            $table->string('source_url')->nullable();
            $table->timestamp('published_at')->useCurrent()->index();
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
        Schema::dropIfExists('report_entries');
        Schema::dropIfExists('report_groups');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('alert_levels');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_link_code', 'telegram_link_expires_at']);
        });
    }
};
