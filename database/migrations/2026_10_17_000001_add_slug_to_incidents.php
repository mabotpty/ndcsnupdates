<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            // Public link for weather warnings, e.g. 20261016-Level6 (set once at creation, then stable).
            $table->string('slug')->nullable()->unique();
        });

        // Give any weather warnings that already exist their link, using the same rule as new ones.
        $taken = [];
        foreach (DB::table('incidents')->where('type', 'weather')->orderBy('id')->get() as $row) {
            $from = \Illuminate\Support\Carbon::parse($row->weather_from ?? $row->published_at, config('app.timezone'));
            $base = $from->format('Ymd').'-Level'.(int) $row->weather_level;
            $slug = $base;
            if (isset($taken[$slug])) {
                $slug = $base.'-'.$from->format('Hi');
                for ($n = 2; isset($taken[$slug]); $n++) {
                    $slug = $base.'-'.$from->format('Hi').'-'.$n;
                }
            }
            $taken[$slug] = true;
            DB::table('incidents')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
