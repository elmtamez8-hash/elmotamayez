<?php

declare(strict_types=1);

use App\Modules\Media\Enums\MediaAssetStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FR-006 — every existing video reference crosses over, or the migration is not
 * done.
 *
 * The migration has already run by the time a test starts, so this rebuilds the
 * old column, fills it with the shapes that were actually in the wild, and
 * replays the transfer by hand. That proves the logic rather than its result.
 */
function replayMediaMigration(string $path): void
{
    $migration = require base_path($path);
    $migration->up();
}

/**
 * A raw insert, so it has to satisfy the schema by hand.
 *
 * `order` is a running counter rather than a constant: since 016 there is a
 * unique(chapter_id, order) index, and four legacy lessons all claiming
 * position 1 in the same chapter is precisely the collision that index exists
 * to make impossible.
 *
 * @param  array<string, mixed>  $overrides
 */
function legacyLesson(mixed $media, array $overrides = []): int
{
    static $order = 0;

    return (int) DB::table('lessons')->insertGetId(array_merge([
        'workspace_id' => 1,
        'course_id' => 1,
        'section_id' => 1,
        'chapter_id' => 1,
        'uuid' => (string) Str::orderedUuid(),
        'title' => 'درس',
        'type' => 'video',
        'status' => 'published',
        'order' => $order++,
        'duration_seconds' => 600,
        'is_preview' => false,
        'is_free' => false,
        'media' => $media,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('carries every shape of the old media column across without losing one', function (): void {
    Schema::dropIfExists('playback_grants');
    Schema::dropIfExists('media_captions');
    Schema::dropIfExists('media_assets');

    Schema::table('lessons', function ($table): void {
        $table->json('media')->nullable();
    });

    // The four shapes the free-form column actually held.
    $plainPath = legacyLesson(json_encode('media/legacy-one.mp4'));
    $withUrl = legacyLesson(json_encode(['url' => 'media/legacy-two.mp4', 'duration' => 900]));
    $malformed = legacyLesson('{not json at all');
    $empty = legacyLesson(null);

    replayMediaMigration('app/Modules/Media/Database/Migrations/2026_08_06_000600_create_media_tables.php');

    $assets = DB::table('media_assets')->orderBy('owner_id')->get()->keyBy('owner_id');

    // Three rows had something; the null one had nothing to carry.
    expect($assets)->toHaveCount(3)
        ->and($assets->has($empty))->toBeFalse();

    expect($assets[$plainPath]->status)->toBe(MediaAssetStatus::Ready->value)
        ->and($assets[$plainPath]->provider_asset_id)->toBe('media/legacy-one.mp4');

    expect($assets[$withUrl]->status)->toBe(MediaAssetStatus::Ready->value)
        ->and($assets[$withUrl]->duration_seconds)->toBe(900);

    // Malformed is migrated as failed, never dropped: the teacher can see what
    // happened and re-upload, whereas a silently discarded row is a lesson that
    // lost its video with no trace of why.
    expect($assets[$malformed]->status)->toBe(MediaAssetStatus::Failed->value)
        ->and($assets[$malformed]->failure_reason)->not->toBeNull();
});

it('drops the column only after the data has moved', function (): void {
    // The two migrations are separate on purpose, and this is what that buys:
    // the backfill can be rolled back with the column still there to hold it.
    expect(Schema::hasColumn('lessons', 'media'))->toBeFalse()
        ->and(Schema::hasTable('media_assets'))->toBeTrue();
});
