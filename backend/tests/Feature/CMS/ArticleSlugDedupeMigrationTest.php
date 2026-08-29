<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| The renumbering runs on data that already collides — which is the only data it
| exists for, and the only data no test database ever holds.
|
| ⚠️ RENUMBERING COMES BEFORE THE INDEX, AND SPEC 016 PAID FOR THE OTHER ORDER.
| An index added over rows that already collide fails on live data and passes on
| an empty test database, so the DEPLOY is the first thing that ever runs it. The
| two migrations are separate files and this one sorts first; this test is what
| says the first one actually clears the ground the second one demands.
|
| ⚠️ THE FIXTURE HAS TO DROP THE INDEX FIRST. By the time a test runs, both
| migrations have been applied — so the duplicate rows the migration exists to fix
| cannot be inserted at all, and a test written without this step passes by never
| reaching the code it names.
*/

/** @param array<string, mixed> $attributes */
function cmsRow(string $slug, array $attributes = []): int
{
    return DB::table('cms_articles')->insertGetId([
        'workspace_id' => 1,
        'uuid' => (string) Str::uuid(),
        'title' => $slug,
        'slug' => $slug,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    Schema::table('cms_articles', function (Blueprint $table): void {
        $table->dropUnique(['slug']);
    });

    $this->migration = require base_path(
        'app/Modules/CMS/Database/Migrations/2026_08_29_000100_dedupe_cms_article_slugs.php',
    );
});

it('keeps the oldest row\'s URL and renumbers everything after it', function (): void {
    $first = cmsRow('خطة-المراجعة');
    $second = cmsRow('خطة-المراجعة');
    $third = cmsRow('خطة-المراجعة');

    $this->migration->up();

    // The oldest keeps what it published: it is the row most likely to be linked
    // from outside, and a published URL that changes is a URL that 404s.
    expect(DB::table('cms_articles')->where('id', $first)->value('slug'))->toBe('خطة-المراجعة')
        ->and(DB::table('cms_articles')->where('id', $second)->value('slug'))->toBe('خطة-المراجعة-2')
        ->and(DB::table('cms_articles')->where('id', $third)->value('slug'))->toBe('خطة-المراجعة-3');
});

it('does not renumber one group into another', function (): void {
    // ⚠️ THE CASE A NAIVE COUNTER GETS WRONG. `خطة-2` is already taken by an
    // unrelated article, so the obvious `slug-2` for the second duplicate would
    // collide with it — and the index the next migration adds would then fail on
    // exactly the data this one claims to have fixed.
    $taken = cmsRow('خطة-2');
    $first = cmsRow('خطة');
    $second = cmsRow('خطة');

    $this->migration->up();

    expect(DB::table('cms_articles')->where('id', $taken)->value('slug'))->toBe('خطة-2')
        ->and(DB::table('cms_articles')->where('id', $first)->value('slug'))->toBe('خطة')
        ->and(DB::table('cms_articles')->where('id', $second)->value('slug'))->toBe('خطة-3');
});

it('counts a soft-deleted row, which holds its slug against the whole table', function (): void {
    // A unique index does not know about `deleted_at`. Deduping only the live
    // rows would leave exactly the collisions the index then rejects.
    $trashed = cmsRow('مقال', ['deleted_at' => now()]);
    $live = cmsRow('مقال');

    $this->migration->up();

    expect(DB::table('cms_articles')->where('id', $trashed)->value('slug'))->toBe('مقال')
        ->and(DB::table('cms_articles')->where('id', $live)->value('slug'))->toBe('مقال-2');
});

it('leaves the table in a state the unique index accepts', function (): void {
    cmsRow('أ');
    cmsRow('أ');
    cmsRow('أ');
    cmsRow('ب');
    cmsRow('ب');

    $this->migration->up();

    // The whole point, asserted as the next migration would experience it rather
    // than as a list of expected strings.
    Schema::table('cms_articles', function (Blueprint $table): void {
        $table->unique('slug');
    });

    expect(DB::table('cms_articles')->distinct()->count('slug'))->toBe(5);
});

it('is a no-op when nothing collides', function (): void {
    cmsRow('أ');
    cmsRow('ب');

    $this->migration->up();

    expect(DB::table('cms_articles')->orderBy('id')->pluck('slug')->all())->toBe(['أ', 'ب']);
});
