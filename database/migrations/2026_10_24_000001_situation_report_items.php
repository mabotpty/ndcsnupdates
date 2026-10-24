<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Situation report entries used to be one area with a multi-line text box of bullets.
     * They are now individual items: road name, area, status.
     */
    public function up(): void
    {
        Schema::table('report_entries', function (Blueprint $table) {
            $table->string('road')->nullable();
        });

        // Keep real information: every bullet of an old entry becomes its own item (same area and status).
        // "No reported issues" placeholders are dropped — an empty section now says that by itself.
        foreach (DB::table('report_entries')->orderBy('id')->get() as $entry) {
            $bullets = array_values(array_filter(
                array_map('trim', preg_split('/\R/', (string) $entry->lines)),
                fn ($b) => $b !== '' && strcasecmp($b, 'No reported issues') !== 0,
            ));

            foreach ($bullets as $bullet) {
                DB::table('report_entries')->insert([
                    'report_group_id' => $entry->report_group_id,
                    'road' => mb_strimwidth($bullet, 0, 120),
                    'area' => $entry->area,
                    'status' => $entry->status,
                    'lines' => '',
                    'sort' => $entry->sort,
                    'created_at' => $entry->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('report_entries')->where('id', $entry->id)->delete();
        }

        Schema::table('report_entries', function (Blueprint $table) {
            $table->dropColumn('lines');
        });
    }

    public function down(): void
    {
        Schema::table('report_entries', function (Blueprint $table) {
            $table->text('lines')->nullable();
        });
        DB::table('report_entries')->update(['lines' => DB::raw('road')]);
        Schema::table('report_entries', function (Blueprint $table) {
            $table->dropColumn('road');
        });
    }
};
