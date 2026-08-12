<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\TeacherSlug;

beforeEach(function () {
    $this->workspace = marketplaceWorkspace('Academy');
});

/**
 * A published teacher with a name we choose.
 *
 * `marketplaceTeacher()` takes profile attributes only and the factory invents
 * the user, but the slug is derived from the NAME — so the user comes first.
 */
function namedTeacher(object $workspace, string $first, string $last): TeacherProfile
{
    $user = User::factory()->create([
        'first_name' => $first,
        'last_name' => $last,
        'platform_role' => 'teacher',
    ]);

    return marketplaceTeacher($workspace, ['user_id' => $user->getKey()]);
}

it('generates a latin slug from an arabic name', function () {
    $teacher = namedTeacher($this->workspace, 'خالد', 'الدوسري');

    // Not `khaled-aldosari`. Arabic writes no short vowels, so nothing can
    // recover them from the script — this asserts the consonant skeleton on
    // purpose, so that a future "improvement" that silently changes every
    // teacher's public URL fails here first.
    expect($teacher->slug)->toBe('khald-aldwsry');
});

it('resolves the public profile by slug', function () {
    $teacher = marketplaceTeacher($this->workspace);

    $this->asGuest();

    $this->getJson("/api/v1/marketplace/teachers/{$teacher->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $teacher->slug);
});

it('still resolves the public profile by uuid', function () {
    // The uuid was the public URL before the slug replaced it. Every link
    // already shared — a message, a bookmark, a search result — is a uuid, and
    // refusing them turns a URL rename into 404s on pages that still exist.
    $teacher = marketplaceTeacher($this->workspace);

    $this->asGuest();

    $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")
        ->assertOk()
        ->assertJsonPath('data.uuid', $teacher->uuid);
});

it('gives a second teacher of the same name a distinct slug', function () {
    $first = namedTeacher($this->workspace, 'أحمد', 'المنصوري');
    $second = namedTeacher($this->workspace, 'أحمد', 'المنصوري');

    expect($second->slug)->not->toBe($first->slug)
        ->and($second->slug)->toBe($first->slug.'-2');
});

it('keeps its slug when the profile is saved again', function () {
    // The uniqueness check must exclude the row being saved. Without that, a
    // teacher re-saving their own profile finds their OWN slug taken, is handed
    // `…-2`, and the URL every link points at dies — on an edit that changed
    // nothing about their name.
    $teacher = marketplaceTeacher($this->workspace);
    $original = $teacher->slug;

    $teacher->headline = 'عنوان جديد';
    $teacher->save();

    expect($teacher->fresh()?->slug)->toBe($original);
});

it('does not follow a rename', function () {
    // A slug that chases the name is a public URL that dies silently: every
    // link already shared 404s and the indexed page drops out. Renaming is a
    // request to change an address, not a side effect of fixing a spelling.
    $teacher = namedTeacher($this->workspace, 'خالد', 'الدوسري');
    $original = $teacher->slug;

    $teacher->user?->forceFill(['first_name' => 'سعيد'])->save();
    $teacher->syncSearchName($teacher->user?->fresh());
    $teacher->save();

    expect($teacher->fresh()?->slug)->toBe($original);
});

it('falls back to a usable segment when a name transliterates to nothing', function () {
    // A URL segment is required, so an unmappable name degrades to something
    // valid rather than to `/teachers/`.
    expect(TeacherSlug::for('•••'))->toBe('teacher');
});

it('keeps slugs unique across workspaces', function () {
    // ⚠️ `/teachers/{slug}` is ONE platform-wide namespace read by guests. A
    // uniqueness check that respected the workspace scope would let a second
    // workspace claim a URL the first already holds — and the unique index
    // would then reject the insert in production, on a signup.
    $other = marketplaceWorkspace('Other Academy');

    $first = namedTeacher($this->workspace, 'منى', 'البلوشي');
    $second = namedTeacher($other, 'منى', 'البلوشي');

    expect($second->slug)->toBe($first->slug.'-2');
});

it('accepts a hand written slug', function () {
    // The generated value is a default, not an answer: `mny-alblwshy` is not how
    // anyone spells the name. MarketplaceSeeder overrides all five demo
    // teachers this way.
    $user = User::factory()->create(['platform_role' => 'teacher']);

    $teacher = TeacherProfile::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $user->getKey(),
        'slug' => 'mona-albalushi',
    ]);

    expect($teacher->fresh()?->slug)->toBe('mona-albalushi');
});
