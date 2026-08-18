<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaFieldAllowlist;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BunnyFixtures;

/*
| FR-019 — WHAT A MEDIA PAYLOAD MAY SAY, WALKED RATHER THAN SPOT-CHECKED.
|
| ⚠️ MEDIA WAS THE ONE MODULE WITHOUT AN ALLOWLIST. Marketplace, Settlement,
| Assessments, Payments and the student balance all have one, each with a test
| that walks real payloads against it. The module whose entire reason for being is
| that a video must not become a shareable link was guarded instead by four
| `not->toContain` needles inside one playback test — and four needles catch the
| four things somebody thought of. A provider id added to a Resource next year is
| caught by none of them.
|
| Asserted in BOTH directions, because an allowlist test that only forbids passes
| happily over an endpoint that returns nothing at all: every key present must be
| listed, and the keys that make the payload useful must be present.
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(BunnyFixtures::config());

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
    $section = Section::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $course->getKey(),
    ]);
    $chapter = Chapter::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
    ]);

    $this->lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'type' => 'video',
        'status' => 'published',
    ]);

    // A commercial provider's asset, with the fields FR-019 forbids actually set
    // — a fixture whose secrets are empty proves nothing about a payload.
    $this->asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => Lesson::class,
        'owner_id' => $this->lesson->getKey(),
        'provider' => 'bunny',
        'provider_asset_id' => 'a-guid-nobody-may-see',
        'status' => MediaAssetStatus::Ready,
    ]);
});

/** Every key in the payload, however deeply nested. */
function mediaKeysOf(mixed $payload, string $prefix = ''): array
{
    if (! is_array($payload)) {
        return [];
    }

    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...mediaKeysOf($value, $prefix)];
    }

    return $keys;
}

it('sends the owner nothing outside the allowlist', function (): void {
    Sanctum::actingAs($this->owner);

    $payload = $this->getJson("/api/v1/media/assets/{$this->asset->uuid}")
        ->assertOk()
        ->json();

    $keys = array_unique(mediaKeysOf($payload));

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(MediaFieldAllowlist::OWNER)->toContain($key);
    }

    // And the other direction: a payload that answered `{}` would satisfy every
    // assertion above.
    expect($keys)->toContain('uuid', 'status', 'failure_reason');
});

/*
| ⚠️ THE VALUES, NOT ONLY THE KEY NAMES. `provider_asset_id` is forbidden as a key
| AND as a value: a hostname or a video id pasted into `failure_reason` — which is
| exactly what a swallowed Guzzle message does — passes a key check untouched.
*/
it('leaks no provider identifier as a value either', function (): void {
    Sanctum::actingAs($this->owner);

    $body = (string) $this->getJson("/api/v1/media/assets/{$this->asset->uuid}")
        ->assertOk()
        ->getContent();

    /*
     * Derived from configuration, not written as literals: the vendor's name may
     * appear in exactly two files under `app/`, and the allowlist is not one of
     * them (`ProviderNameContainmentTest` refuses it, and rightly — a forbidden
     * list that names the vendor puts the name in one more place). Here, in a
     * test, it is allowed to be read from the config that already holds it.
     */
    $needles = array_filter([
        'a-guid-nobody-may-see',
        (string) config('media.provider'),
        (string) config('media.bunny.pull_zone'),
        (string) config('media.bunny.library_id'),
        (string) config('media.bunny.access_key'),
    ], fn (string $needle): bool => $needle !== '');

    // A fixture whose secrets are empty proves nothing, so the list itself is
    // asserted before it is used.
    expect($needles)->toHaveCount(5);

    foreach ($needles as $needle) {
        expect(strtolower($body))->not->toContain(strtolower($needle));
    }

    foreach (MediaFieldAllowlist::FORBIDDEN as $forbidden) {
        expect(mediaKeysOf($this->getJson("/api/v1/media/assets/{$this->asset->uuid}")->json()))
            ->not->toContain($forbidden);
    }
});
