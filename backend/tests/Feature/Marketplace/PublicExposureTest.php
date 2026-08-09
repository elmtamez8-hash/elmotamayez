<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Marketplace\Support\PublicFieldAllowlist;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;

/**
 * The guard test for the public marketplace.
 *
 * WorkspaceScope adds no condition when there is no authenticated user, so on a
 * guest request tenant isolation is simply absent. publiclyListed() stands in its
 * place. If this file fails, the marketplace is leaking across workspaces — stop
 * and fix that before anything else.
 */
/**
 * Every string key in a nested payload, flattened.
 *
 * @return list<string>
 */
function marketplacePayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...marketplacePayloadKeys($child)];
    }

    return $keys;
}

it('lists teachers from several participating workspaces in one feed', function () {
    foreach (['Academy A', 'Academy B', 'Academy C'] as $name) {
        marketplaceTeacher(marketplaceWorkspace($name));
    }

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3);
});

it('hides teachers whose workspace has not opted into the marketplace', function () {
    marketplaceTeacher(marketplaceWorkspace('Opted In'));
    $hidden = marketplaceTeacher(marketplaceWorkspace('Opted Out', participates: false));

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);

    $this->getJson("/api/v1/marketplace/teachers/{$hidden->uuid}")->assertNotFound();
});

it('hides teachers that are not approved', function (string $status) {
    $workspace = marketplaceWorkspace('Academy');

    // is_publicly_listed is deliberately left true: approval state must gate on its
    // own, so a stale flag cannot publish a suspended teacher.
    $teacher = marketplaceTeacher($workspace, ['approval_status' => $status]);

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0);
    $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")->assertNotFound();
})->with([
    TeacherProfile::STATUS_PENDING,
    TeacherProfile::STATUS_REJECTED,
    TeacherProfile::STATUS_SUSPENDED,
]);

/**
 * Envelope and filter keys, which belong to no resource.
 *
 * Listed here rather than added to PublicFieldAllowlist: that class is the set of
 * FIELDS a visitor may be told about a teacher or a course, and diluting it with
 * `current_page` would make "is this key allowed" answerable by the wrong list.
 *
 * @return list<string>
 */
function marketplaceEnvelopeKeys(): array
{
    return [
        'data', 'meta', 'links', 'current_page', 'per_page', 'total', 'last_page',
        'filters', 'subject', 'grade_level', 'min_rating', 'min_trust_score',
        'language', 'available_now', 'q', 'sort', 'type', 'teacher', 'page',
        'featured_teachers', 'featured_courses', 'subjects', 'grade_levels', 'stats',
        'testimonials', 'faqs',
    ];
}

/*
| ⚠️ AN ALLOWLIST, NOT A DENYLIST — and the difference is the whole test.
|
| This used to assert only that no key was in FORBIDDEN, which meant the seven
| allow constants were referenced by nothing at all: removing `hourly_rate` from
| TEACHER_CARD changed not one assertion, and a field ADDED to a Resource was
| published with no test to notice. The constants documented an intention the
| suite could not check.
|
| Now every key in every public payload must appear in the union of the allow
| constants. A new field on a public Resource fails here until someone writes it
| into the list, which is the deliberate decision the class docblock asks for.
*/
it('publishes only allowlisted fields, in every public payload', function () {
    $teacher = marketplaceTeacher(marketplaceWorkspace('Academy'));

    $this->asGuest();

    $allowed = [
        ...PublicFieldAllowlist::TEACHER_DETAIL,
        ...PublicFieldAllowlist::COURSE_CARD,
        ...PublicFieldAllowlist::TAXONOMY,
        ...PublicFieldAllowlist::REVIEW,
        ...PublicFieldAllowlist::AVAILABILITY,
        ...PublicFieldAllowlist::STATS,
        ...PublicFieldAllowlist::TEACHER_STATS,
        ...PublicFieldAllowlist::TRUST_FACTORS,
        ...PublicFieldAllowlist::REVIEW_SUMMARY,
        ...PublicFieldAllowlist::TESTIMONIAL,
        ...PublicFieldAllowlist::FAQ,
        ...marketplaceEnvelopeKeys(),
    ];

    $payloads = [
        'teacher list' => $this->getJson('/api/v1/marketplace/teachers')->json(),
        'teacher detail' => $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")->json(),
        'course list' => $this->getJson('/api/v1/marketplace/courses')->json(),
        'home' => $this->getJson('/api/v1/marketplace/home')->json(),
        'subjects' => $this->getJson('/api/v1/marketplace/subjects')->json(),
        'stats' => $this->getJson('/api/v1/marketplace/stats')->json(),
    ];

    $unlisted = [];
    $forbidden = [];

    foreach ($payloads as $label => $payload) {
        foreach (array_unique(marketplacePayloadKeys($payload)) as $key) {
            if (! in_array($key, $allowed, true)) {
                $unlisted[] = "{$label}.{$key}";
            }

            // Checked alongside rather than instead: FORBIDDEN is the stronger
            // statement — never, at any depth — so a key wrongly written into an
            // allow constant still fails here.
            if (in_array($key, PublicFieldAllowlist::FORBIDDEN, true)) {
                $forbidden[] = "{$label}.{$key}";
            }
        }
    }

    // Collected and asserted once, so a failure names EVERY leaking key instead
    // of the first one and then stopping — a per-key assertion turns one review
    // into as many runs as there are new fields.
    expect($unlisted)->toBe([], 'published fields that are on no allowlist')
        ->and($forbidden)->toBe([], 'published fields that are forbidden outright');
});

/*
| FR-021و · FR-021هـ — the two fields spec 006 took off the public surface.
|
| Named explicitly rather than left to the allowlist check above, because the
| allowlist proves "nothing unlisted is published" and this proves "these two
| specifically are gone". Delete them from the constants AND forget the Resource
| and the first test still passes on a payload that leaks.
*/
it('publishes neither the teacher rate nor a course price on any browse surface', function () {
    $teacher = marketplaceTeacher(marketplaceWorkspace('Academy'), ['hourly_rate' => 275]);

    $this->asGuest();

    foreach ([
        '/api/v1/marketplace/teachers',
        "/api/v1/marketplace/teachers/{$teacher->uuid}",
        '/api/v1/marketplace/courses',
        '/api/v1/marketplace/home',
    ] as $url) {
        $keys = marketplacePayloadKeys($this->getJson($url)->json());

        expect($keys)->not->toContain('hourly_rate', "{$url} published the teacher's rate")
            ->and($keys)->not->toContain('price', "{$url} published a price on a browse surface")
            ->and($keys)->not->toContain('price_before_discount');
    }
});

it('refuses a price filter rather than ignoring it', function () {
    $this->asGuest();

    // Silently ignoring the parameter would leave an old bookmark returning a
    // full, unfiltered list that looks filtered. And a range filter is a price
    // oracle in its own right: binary search on price_min reads a rate to the
    // riyal without the field ever being published.
    $this->getJson('/api/v1/marketplace/teachers?price_min=50')->assertStatus(422);
    $this->getJson('/api/v1/marketplace/teachers?price_max=500')->assertStatus(422);
    $this->getJson('/api/v1/marketplace/teachers?sort=price_asc')->assertStatus(422);

    $this->getJson('/api/v1/marketplace/courses?price_min=50')->assertStatus(422);
    $this->getJson('/api/v1/marketplace/courses?sort=price_asc')->assertStatus(422);
});

it('drops every listing when a workspace withdraws from the marketplace', function () {
    $workspace = marketplaceWorkspace('Academy');
    marketplaceTeacher($workspace);

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);

    $workspace->forceFill(['participates_in_marketplace' => false])->save();
    // The column write here stands in for SetMarketplaceParticipation (US4), which
    // owns this flush in production. Without it the withdrawal would still take
    // effect, but only once the 60s TTL expired.
    MarketplaceCache::flush();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0);
});

it('returns an empty list rather than a 404 when filters match nothing', function () {
    marketplaceTeacher(marketplaceWorkspace('Academy'));

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers?subject=nonexistent-subject');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
});

it('counts only what a visitor would actually get, not the drafts behind it', function () {
    $workspace = marketplaceWorkspace('Academy');
    $teacher = marketplaceTeacher($workspace);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher): void {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'visibility' => 'public',
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->id,
            'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->id,
            'course_id' => $course->id, 'title' => 'الفصل',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $make = fn (string $title, ContentStatus $status, int $order) => Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => $title, 'type' => 'article',
            'content' => 'نصّ', 'status' => $status, 'order' => $order,
        ]);

        $make('منشور', ContentStatus::Published, 1);
        $make('مسودّة', ContentStatus::Draft, 2);
        $make('مؤرشف', ContentStatus::Archived, 3);
    });

    MarketplaceCache::flush();

    $this->asGuest();

    $courses = $this->getJson('/api/v1/marketplace/courses')->json('data');

    // One, not three. `withCount('lessons')` counted every row, so a teacher's
    // half-written drafts and their retired archive both inflated the number a
    // visitor is shown — the course advertised three items and opens one.
    // FR-062 bans a draft item's fields from a public payload, and a count
    // computed over those items is the same leak arriving as a single number.
    expect(collect($courses)->pluck('lessons_count')->all())->toBe([1]);
});
