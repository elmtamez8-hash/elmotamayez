<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate from the backfill on purpose: down() here restores the columns and
     * the data, which it could not do if the same migration had both moved and
     * dropped.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['grade_level_slug', 'registered_by_parent']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('grade_level_slug')->nullable();
            $table->boolean('registered_by_parent')->default(false);
        });

        DB::table('student_profiles')->orderBy('id')->chunk(500, function ($profiles): void {
            foreach ($profiles as $profile) {
                DB::table('users')->where('id', $profile->user_id)->update([
                    'grade_level_slug' => $profile->grade_level_slug,
                    'registered_by_parent' => $profile->registered_by_parent,
                ]);
            }
        });
    }
};
