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
            $table->string('type')->default('incident')->index(); // incident | weather
            $table->string('weather_kind')->nullable();           // e.g. Heavy rain
            $table->unsignedTinyInteger('weather_level')->nullable(); // 1-10
            $table->string('area')->nullable();
            $table->timestamp('weather_from')->nullable();
            $table->timestamp('weather_to')->nullable();
            $table->text('narrative')->nullable();
        });

        Schema::create('weather_levels', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->primary(); // 0 = nothing active, 1-10 warning levels
            $table->string('name');
            $table->string('colour'); // green | yellow | orange | red
            $table->string('description');
        });

        // South African Weather Service (SAWS) warning key: level, colour, likelihood, impact.
        // Editable afterwards in the admin dashboard.
        $rows = [['level' => 0, 'name' => 'No weather warnings', 'colour' => 'green', 'description' => 'No weather warnings are currently active.']];
        foreach ([
            1 => ['yellow', 'Medium', 'Minor'],
            2 => ['yellow', 'High', 'Minor'],
            3 => ['yellow', 'Very Low', 'Significant'],
            4 => ['yellow', 'Low', 'Significant'],
            5 => ['orange', 'Medium', 'Significant'],
            6 => ['orange', 'High', 'Significant'],
            7 => ['yellow', 'Very Low', 'Severe'],
            8 => ['orange', 'Low', 'Severe'],
            9 => ['orange', 'Medium', 'Severe'],
            10 => ['red', 'High', 'Severe'],
        ] as $n => [$colour, $likelihood, $impact]) {
            $rows[] = [
                'level' => $n,
                'name' => ucfirst($colour)." - Level {$n}",
                'colour' => $colour,
                'description' => "{$likelihood} Likelihood, {$impact} Impact",
            ];
        }
        DB::table('weather_levels')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_levels');
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['type', 'weather_kind', 'weather_level', 'area', 'weather_from', 'weather_to', 'narrative']);
        });
    }
};
