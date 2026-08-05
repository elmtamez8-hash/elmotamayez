<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SC-016 — the migration carries every existing row across, or it is not done.
 *
 * The two migrations under test have already run by the time a test starts, so
 * these rebuild the old tables, put rows in them, and re-run the migration by
 * hand. That is more faithful than asserting on the migrated schema: it proves
 * the transfer logic, not just its result.
 */
function replayMigration(string $path): void
{
    $migration = require base_path($path);
    $migration->up();
}

it('carries every parent_child_link into the new relations table', function (): void {
    $parentA = User::factory()->create();
    $parentB = User::factory()->create();
    $child = User::factory()->create();

    Schema::dropIfExists('parent_student_relations');

    Schema::create('parent_child_links', function ($table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->unsignedBigInteger('parent_id')->index();
        $table->unsignedBigInteger('child_id')->nullable()->index();
        $table->string('child_name', 150);
        $table->unsignedTinyInteger('child_age')->nullable();
        $table->string('child_grade_level_slug', 100)->nullable();
        $table->timestamps();
    });

    $keptUuid = (string) Str::orderedUuid();

    DB::table('parent_child_links')->insert([
        [
            'uuid' => $keptUuid,
            'parent_id' => $parentA->getKey(),
            'child_id' => $child->getKey(),
            'child_name' => 'سلمى',
            'child_age' => 14,
            'child_grade_level_slug' => 'secondary',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid' => (string) Str::orderedUuid(),
            'parent_id' => $parentB->getKey(),
            // A child with no account yet — the common case at signup.
            'child_id' => null,
            'child_name' => 'يوسف',
            'child_age' => null,
            'child_grade_level_slug' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    replayMigration('app/Modules/Identity/Database/Migrations/2026_08_05_000700_create_parent_student_relations_table.php');

    $rows = DB::table('parent_student_relations')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        // Nothing lost, and the old table is gone rather than left as a second
        // source of truth.
        ->and(Schema::hasTable('parent_child_links'))->toBeFalse();

    $first = $rows->first();

    expect($first->student_name)->toBe('سلمى')
        ->and($first->student_age)->toBe(14)
        ->and($first->relation_type)->toBe(RelationType::Parent->value)
        ->and($first->status)->toBe(RelationStatus::Active->value)
        ->and(json_decode((string) $first->permissions, true))->toBe(GuardianPermission::values())
        // The uuid survives, so any link already handed out still resolves.
        ->and($first->uuid)->toBe($keptUuid);

    expect($rows->last()->student_user_id)->toBeNull();
});

it('carries a session_alerts opt-out into the new preference shape', function (): void {
    $optedOut = User::factory()->create();
    $untouched = User::factory()->create();

    Schema::dropIfExists('notification_preferences');

    Schema::create('notification_preferences', function ($table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->unsignedBigInteger('user_id')->unique();
        $table->boolean('weekly_reports')->default(true);
        $table->boolean('session_alerts')->default(true);
        $table->timestamps();
    });

    DB::table('notification_preferences')->insert([
        [
            'uuid' => (string) Str::orderedUuid(),
            'user_id' => $optedOut->getKey(),
            'weekly_reports' => true,
            'session_alerts' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid' => (string) Str::orderedUuid(),
            'user_id' => $untouched->getKey(),
            'weekly_reports' => true,
            'session_alerts' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    replayMigration('app/Modules/Notifications/Database/Migrations/2026_08_05_000300_rebuild_notification_preferences_table.php');

    $carried = DB::table('notification_preferences')
        ->where('user_id', $optedOut->getKey())
        ->pluck('channels', 'type');

    // "No session alerts" becomes an empty channel list on the two types that
    // meant.
    expect($carried)->toHaveCount(2)
        ->and($carried[NotificationType::AttendanceAlert->value])->toBe('[]')
        ->and($carried[NotificationType::AppointmentReminder->value])->toBe('[]');

    // Someone who changed nothing gets no rows: absence means "defaults apply",
    // which is both correct and one less row per user.
    expect(DB::table('notification_preferences')->where('user_id', $untouched->getKey())->count())->toBe(0);
});
