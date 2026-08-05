<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is true of a student and nobody else moves off `users`.
     *
     * `users` is read on every authenticated request, and a column that is
     * meaningful for one role is a null carried by every other. The sharper cost
     * is not storage: a grade_level_slug sitting on `users` invites every screen
     * to read it for any user, while a student_profiles row makes "is this a
     * student?" a question you have to ask before "what grade?".
     *
     * Platform-owned: no BelongsToWorkspace. A student's grade is one fact across
     * every teacher they study with.
     *
     * Moves data only. Dropping the old columns is the next migration, so a
     * partial backfill has something to roll back to.
     */
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('grade_level_slug')->nullable()->index();
            $table->boolean('registered_by_parent')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('users')
            ->select(['id', 'grade_level_slug', 'registered_by_parent'])
            ->where(function ($query): void {
                $query->whereNotNull('grade_level_slug')
                    ->orWhere('registered_by_parent', true);
            })
            ->orderBy('id')
            ->chunk(500, function ($users) use ($now): void {
                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'grade_level_slug' => $user->grade_level_slug,
                        'registered_by_parent' => (bool) $user->registered_by_parent,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('student_profiles')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
