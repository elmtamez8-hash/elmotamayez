<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Actions\ModerateReview;
use App\Modules\Marketplace\Actions\Public\ShowPublicTeacher;
use App\Modules\Marketplace\Actions\SubmitReview;
use App\Modules\Marketplace\Jobs\RecalculateTrustScoreJob;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may review, how often, and what the public sees afterwards.
 *
 * The rule under test throughout is that a rating is evidence of a completed
 * session, not an opinion anyone can post — the marketplace's whole trust display
 * is built on that being true.
 */
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
    $this->teacher = marketplaceTeacher($this->workspace);
});

it('refuses a review from a student with no completed session', function (): void {
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);

    postReview($stranger, $this->teacher->uuid)
        ->assertStatus(422);

    expect(Review::query()->withoutWorkspaceScope()->count())->toBe(0);
});

// The FormRequest cannot express this rule — it needs the teacher and the
// enrollment history — so it lives in the Action, and this proves the Action
// refuses even when nothing HTTP is involved (Constitution II).
it('refuses at the Action, not only at the request boundary', function (): void {
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);

    expect(fn () => app(SubmitReview::class)->handle($this->teacher, $stranger, [
        'punctuality' => 5,
        'clarity' => 5,
        'engagement' => 5,
    ]))->toThrow(DomainException::class);
});

it('accepts a review from a student who finished one of the teacher courses', function (): void {
    $student = studentWhoAttendedWith($this->teacher);

    postReview($student, $this->teacher->uuid, 4, 'شرح واضح')
        ->assertStatus(201)
        ->assertJsonPath('rating', 4);

    expect(Review::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(Review::query()->withoutWorkspaceScope()->first()?->workspace_id)
        ->toBe($this->workspace->getKey());
});

it('updates the existing row when the same student reviews again', function (): void {
    $student = studentWhoAttendedWith($this->teacher);

    postReview($student, $this->teacher->uuid, 5)->assertStatus(201);
    postReview($student, $this->teacher->uuid, 2, 'غيّرت رأيي')->assertStatus(200);

    $reviews = Review::query()->withoutWorkspaceScope()->get();

    expect($reviews)->toHaveCount(1)
        ->and($reviews->first()?->rating)->toBe(2);
});

// The reason the unique pair exists: two rows from one student would drag the
// public average with them.
it('does not inflate the average when a student re-reviews', function (): void {
    $generous = studentWhoAttendedWith($this->teacher);
    $harsh = studentWhoAttendedWith($this->teacher);

    postReview($generous, $this->teacher->uuid, 5);
    postReview($generous, $this->teacher->uuid, 5);
    postReview($harsh, $this->teacher->uuid, 1);

    expect((float) ($this->teacher->fresh()?->average_rating ?? 0))->toBe(3.0);
});

// The definite article is not an initial: a large share of Arab family names
// begin with "ال", and taking character zero would abbreviate all of them to the
// same "ا." — an initial that distinguishes nobody.
it('abbreviates a surname past the definite article', function (string $surname, string $expected): void {
    $student = studentWhoAttendedWith($this->teacher);
    $student->forceFill(['first_name' => 'أحمد', 'last_name' => $surname])->save();

    postReview($student, $this->teacher->uuid, 5);

    $this->asGuest();
    $items = $this->getJson("/api/v1/marketplace/teachers/{$this->teacher->uuid}")->json('data.reviews.items');

    expect($items[0]['student_display_name'])->toBe($expected);
})->with([
    ['مبارك', 'أحمد م.'],
    ['الكواري', 'أحمد ك.'],
    ['العطية', 'أحمد ع.'],
    // Two letters long: "ال" here is the whole name, not an article to strip.
    ['ال', 'أحمد ا.'],
    ['', 'أحمد'],
]);

it('shows reviews on the public profile under a shortened student name', function (): void {
    $student = studentWhoAttendedWith($this->teacher);
    $student->forceFill(['first_name' => 'أحمد', 'last_name' => 'مبارك'])->save();

    postReview($student, $this->teacher->uuid, 5, 'أفضل مدرّس');

    $this->asGuest();
    $payload = $this->getJson("/api/v1/marketplace/teachers/{$this->teacher->uuid}")->json('data.reviews');

    expect($payload['total'])->toBe(1)
        ->and($payload['average'])->toBe(5)
        ->and($payload['distribution']['5'])->toBe(1)
        ->and($payload['items'][0]['student_display_name'])->toBe('أحمد م.')
        // Never the full name, never the email — the teacher being rated is the
        // one person who must not be able to identify the reviewer (FR-021).
        ->and($payload['items'][0])->not->toHaveKey('email');
});

/*
| FR-034 from the screen's side. The report route took a review uuid while the
| public profile published none, so «إبلاغ» had nothing to post — a route no
| client could reach. The round trip is the assertion: the uuid read off the
| profile is the one the report door accepts.
*/
it('publishes each review uuid, and that uuid is what the report route accepts', function (): void {
    $student = studentWhoAttendedWith($this->teacher);
    postReview($student, $this->teacher->uuid, 2, 'rude comment');

    $this->asGuest();
    $items = $this->getJson("/api/v1/marketplace/teachers/{$this->teacher->uuid}")->json('data.reviews.items');
    $uuid = $items[0]['uuid'] ?? null;

    expect($uuid)->toBeString()->not->toBe('');

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/reviews/{$uuid}/report", ['reason' => 'spam'])
        ->assertStatus(202);

    $this->assertDatabaseHas('moderation_actions', [
        'subject_type' => 'review',
        'reason' => 'spam',
    ]);
});

it('drops a hidden review from the public payload and the average', function (): void {
    $kept = studentWhoAttendedWith($this->teacher);
    $hidden = studentWhoAttendedWith($this->teacher);

    postReview($kept, $this->teacher->uuid, 4);
    postReview($hidden, $this->teacher->uuid, 1, 'إساءة');

    $abusive = Review::query()->withoutWorkspaceScope()->where('rating', 1)->firstOrFail();
    app(ModerateReview::class)->handle($abusive);

    $this->asGuest();
    $payload = $this->getJson("/api/v1/marketplace/teachers/{$this->teacher->uuid}")->json('data.reviews');

    expect($payload['total'])->toBe(1)
        ->and($payload['average'])->toBe(4)
        ->and($payload['distribution']['1'])->toBe(0)
        // Hidden, not deleted: deleting would free the unique pair and hand the
        // author a second slot.
        ->and(Review::query()->withoutWorkspaceScope()->count())->toBe(2);
});

it('counts every visible review but loads only the page it shows', function (): void {
    /*
    | The summary is aggregated in SQL and the list is LIMITed, so the two can
    | disagree in exactly one way that matters: the totals must describe every
    | visible review, not the page. A limit of two over three reviews is where a
    | summary accidentally computed from the loaded rows would show.
    */
    foreach ([5, 4, 4] as $rating) {
        postReview(studentWhoAttendedWith($this->teacher), $this->teacher->uuid, $rating);
    }

    postReview(studentWhoAttendedWith($this->teacher), $this->teacher->uuid, 1, 'إساءة');
    app(ModerateReview::class)->handle(Review::query()->withoutWorkspaceScope()->where('rating', 1)->firstOrFail());

    [$queries, $payload] = countingQueries(fn () => app(ShowPublicTeacher::class)->reviewsOf($this->teacher, 2));

    expect($payload['total'])->toBe(3)
        ->and($payload['average'])->toBe(4.33)
        ->and((array) $payload['distribution'])->toBe(['5' => 1, '4' => 2, '3' => 0, '2' => 0, '1' => 0])
        ->and($payload['items'])->toHaveCount(2)
        // The aggregate, the page, and its two eager loads — whatever the count.
        ->and($queries)->toBe(4);
});

it('refuses moderation without the permission', function (): void {
    $student = studentWhoAttendedWith($this->teacher);
    postReview($student, $this->teacher->uuid, 3);

    $review = Review::query()->withoutWorkspaceScope()->firstOrFail();
    $member = $this->addWorkspaceMember($this->workspace);

    Sanctum::actingAs($member);
    $this->setCurrentWorkspace($this->workspace, $member);

    $this->deleteJson("/api/v1/admin/reviews/{$review->uuid}")->assertStatus(403);
});

it('hides a review over the API for a moderator', function (): void {
    $student = studentWhoAttendedWith($this->teacher);
    postReview($student, $this->teacher->uuid, 3);

    $review = Review::query()->withoutWorkspaceScope()->firstOrFail();
    $moderator = reviewModerator($this->workspace);

    Sanctum::actingAs($moderator);
    $this->asGuest();
    $this->setCurrentWorkspace($this->workspace, $moderator);

    $this->deleteJson("/api/v1/admin/reviews/{$review->uuid}")->assertOk();

    expect($review->fresh()?->is_visible)->toBeFalse();
});

it('queues a recalculation for every event that can move the score', function (): void {
    Queue::fake();

    $student = studentWhoAttendedWith($this->teacher);
    postReview($student, $this->teacher->uuid, 5);

    Queue::assertPushed(RecalculateTrustScoreJob::class, 1);

    app(ModerateReview::class)->handle(Review::query()->withoutWorkspaceScope()->firstOrFail());

    Queue::assertPushed(RecalculateTrustScoreJob::class, 2);
});

it('refuses a teacher reviewing their own profile', function (): void {
    /** @var User $self */
    $self = $this->teacher->user;

    postReview($self, $this->teacher->uuid)->assertStatus(403);
});

/** A workspace member holding only the review-moderation permission. */
function reviewModerator(Workspace $workspace): User
{
    $user = test()->addWorkspaceMember($workspace);

    app(WorkspaceContext::class)->set($workspace);
    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = Role::findOrCreate('moderator', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::MARKETPLACE_REVIEWS_MODERATE, 'web'));
    $user->assignRole($role);

    return $user->refresh();
}
