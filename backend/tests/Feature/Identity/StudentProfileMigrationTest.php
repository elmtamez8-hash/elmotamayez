<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role-specific columns leave `users` without losing a row.
 *
 * Same replay approach as the media migration: rebuild the old columns, fill
 * them, and run the transfer by hand.
 */
function replayProfileMigration(string $path): void
{
    $migration = require base_path($path);
    $migration->up();
}

it('moves every student column into student_profiles', function (): void {
    Schema::dropIfExists('student_profiles');

    Schema::table('users', function ($table): void {
        $table->string('grade_level_slug')->nullable();
        $table->boolean('registered_by_parent')->default(false);
    });

    $withGrade = User::factory()->create();
    $registeredByParent = User::factory()->create();
    $teacher = User::factory()->create();

    DB::table('users')->where('id', $withGrade->getKey())
        ->update(['grade_level_slug' => 'secondary']);
    DB::table('users')->where('id', $registeredByParent->getKey())
        ->update(['registered_by_parent' => true]);

    replayProfileMigration('app/Modules/Identity/Database/Migrations/2026_08_06_000200_create_student_profiles_table.php');

    $profiles = DB::table('student_profiles')->get()->keyBy('user_id');

    expect($profiles)->toHaveCount(2)
        ->and($profiles[$withGrade->getKey()]->grade_level_slug)->toBe('secondary')
        ->and((bool) $profiles[$registeredByParent->getKey()]->registered_by_parent)->toBeTrue()
        // Nobody who is not a student gets a row. That is the point of the split:
        // "is this a student?" becomes a question you have to ask.
        ->and($profiles->has($teacher->getKey()))->toBeFalse();
});

it('leaves the relation snapshot alone', function (): void {
    $guardian = User::factory()->create();
    $student = User::factory()->create();

    $relation = ParentStudentRelation::factory()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_grade_level_slug' => 'primary',
    ]);

    // A historical snapshot on the relation, not a pointer at the profile — it
    // records what was true when the link was made and must not follow later edits.
    expect($relation->fresh()->student_grade_level_slug)->toBe('primary');
});

it('carries nothing on users any more', function (): void {
    expect(Schema::hasColumn('users', 'grade_level_slug'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'registered_by_parent'))->toBeFalse()
        ->and(Schema::hasTable('student_profiles'))->toBeTrue()
        // Not created: no field is a guardian's or an admin's today, and an empty
        // table is not a design.
        ->and(Schema::hasTable('guardian_profiles'))->toBeFalse()
        ->and(Schema::hasTable('admin_profiles'))->toBeFalse();
});

it('keeps what every account has on users', function (): void {
    // phone and country stay: a student, a teacher and a guardian all have them,
    // and the watermark reads the phone for one while a future channel reads it
    // for another.
    expect(Schema::hasColumn('users', 'phone'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'country'))->toBeTrue()
        // The discriminator itself stays too: moving it would mean a query to
        // find out which table to query.
        ->and(Schema::hasColumn('users', 'platform_role'))->toBeTrue();
});
