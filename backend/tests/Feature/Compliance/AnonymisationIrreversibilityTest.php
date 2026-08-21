<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Support\Anonymiser;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Payments\Models\TermsConsent;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SC-008 — the anonymisation cannot be undone, or worked back to.
 *
 * ⚠️ THE TEST SEARCHES THE WHOLE DATABASE, NOT ONE COLUMN. Checking that
 * `users.first_name` changed proves the write happened and nothing else; the
 * question this criterion asks is whether the person can be recovered from what
 * REMAINS — a phone number left in a verification row, an address in an invitation,
 * their own words in a review, the IP they consented from. Every one of those is a
 * different table, and each was reachable before this phase.
 *
 * ⚠️ AND FIXED VALUES, NEVER HASHES. A hash looks like the careful choice and is
 * the opposite of one here: a Qatari mobile has about eight variable digits, so a
 * hashed phone is brute-forced back in minutes on a laptop, and a hashed name is
 * worse because the platform's own user table is the dictionary. A hash is a
 * reversible value wearing a one-way name.
 */
const IDENTIFYING = [
    'Zubaida',
    'Al-Khalifa',
    'zubaida.identifier@example.test',
    '+97455590001',
    '203.0.113.77',
    'ZZ-HER-OWN-WORDS-ZZ',
];

/** Every string value in every table, as one searchable blob. */
function wholeDatabaseText(): string
{
    $text = '';

    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
        $name = (string) $table->name;

        if (str_starts_with($name, 'sqlite_')) {
            continue;
        }

        foreach (DB::table($name)->get() as $row) {
            $text .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    return $text;
}

beforeEach(function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $this->subject = User::factory()->create([
        'first_name' => 'Zubaida',
        'last_name' => 'Al-Khalifa',
        'email' => 'zubaida.identifier@example.test',
        'phone' => '+97455590001',
    ]);

    ContactVerification::query()->create([
        'user_id' => $this->subject->getKey(),
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97455590001',
        'code_hash' => 'x',
        'expires_at' => now()->subMinute(),
        'verified_at' => now(),
    ]);

    TermsConsent::query()->create([
        'user_id' => $this->subject->getKey(),
        'student_user_id' => $this->subject->getKey(),
        'document' => 'data_processing',
        'version' => '1.0',
        'decision' => 'granted',
        'ip_address' => '203.0.113.77',
        'user_agent' => 'Mozilla/5.0',
        'consented_at' => now(),
    ]);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner): void {
        $teacher = TeacherProfile::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
        ]);

        Enrollment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => Course::factory()->create(['workspace_id' => $workspace->getKey()])->getKey(),
            'student_user_id' => $this->subject->getKey(),
            'status' => 'active',
        ]);

        Review::query()->create([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $teacher->getKey(),
            'student_id' => $this->subject->getKey(),
            'rating' => 5,
            'comment' => 'ZZ-HER-OWN-WORDS-ZZ',
            'is_visible' => true,
        ]);
    });
});

it('leaves nothing anywhere in the database that names them', function (): void {
    // The control: every identifier IS findable before the erasure, so a green
    // result below cannot come from a search that looks in the wrong place.
    $before = wholeDatabaseText();

    foreach (IDENTIFYING as $needle) {
        expect($before)->toContain($needle);
    }

    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $after = wholeDatabaseText();

    foreach (IDENTIFYING as $needle) {
        expect($after)->not->toContain($needle);
    }
});

/*
 * ⚠️ THE REPLACEMENT VALUE IS FIXED, AND THE EMAIL CARRIES THE ROW ID RATHER THAN
 * ANYTHING ABOUT THE PERSON.
 *
 * `users.email` is UNIQUE, so a constant would make the SECOND erasure on the
 * platform fail with a constraint violation — and the id is already the primary key
 * of the row the value sits in. It identifies the ROW, which must survive; it says
 * nothing about the PERSON, which must not.
 */
it('replaces the identity with a fixed value that identifies only the row', function (): void {
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $user = User::query()->find($this->subject->getKey());
    $anonymiser = app(Anonymiser::class);

    expect($user?->first_name)->toBe($anonymiser->name())
        ->and($user?->email)->toBe($anonymiser->email((int) $this->subject->getKey()));
});

/*
 * ⚠️ FR-023 — AND THE ERASED TEACHER'S PUBLIC PAGE COMES DOWN WITH THEM.
 *
 * This is the one surface no other assertion in this phase reaches. A
 * `teacher_profiles` row survives every module's erasure by design — it is what
 * every course, session and settlement line points at — so unless Marketplace
 * unlists it, `publiclyListed()` keeps returning it and the marketplace renders
 * the person's own bio and qualifications under the anonymised name. The URL is
 * worse than the page: `slug` is frozen at creation on purpose, so it still spells
 * out the real name of somebody who asked to be forgotten. Unlisting is what makes
 * the frozen slug harmless.
 *
 * ⚠️ AND `search_name` IS ASSERTED SEPARATELY, because it is a DENORMALISED COPY of
 * the name in another table. It is kept in step by a `User::updated` hook in
 * `MarketplaceServiceProvider` — which the erasure's `forceFill(...)->save()` does
 * fire — but that is a coupling across two modules held together by an event, and
 * an assertion is cheaper than trusting it stays wired.
 */
it('takes the erased teacher off the marketplace, name and page alike', function (): void {
    /*
    | `marketplaceWorkspace()` rather than `createWorkspaceWithOwner()`: public
    | listing is derived from workspace PARTICIPATION as well as approval, so a
    | plain workspace produces a profile that is already invisible — and the control
    | assertion below would pass before the erasure ran, proving nothing.
    */
    $workspace = marketplaceWorkspace('Erasure Academy');
    $profile = marketplaceTeacher($workspace, ['bio' => 'ZZ-MY-OWN-BIO-ZZ']);
    $teacher = $profile->user;

    expect(TeacherProfile::query()->withoutWorkspaceScope()->publiclyListed()->whereKey($profile->getKey())->exists())
        ->toBeTrue();

    $request = app(CreateDataRequest::class)->handle($teacher, (string) $teacher->uuid, DataRequestType::Erasure);
    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $profile->refresh();

    expect(TeacherProfile::query()->withoutWorkspaceScope()->publiclyListed()->whereKey($profile->getKey())->exists())
        ->toBeFalse()
        ->and($profile->bio)->toBeNull()
        ->and($profile->search_name)->not->toContain($teacher->first_name);
});

/*
 * ⚠️ AND A SECOND PERSON ERASED DOES NOT COLLIDE WITH THE FIRST.
 *
 * The failure this catches is a constraint violation on the second erasure ever
 * performed — invisible in any fixture with one subject, and fatal in production on
 * the day two people ask.
 */
it('erases a second person without colliding with the first', function (): void {
    $other = User::factory()->create();

    foreach ([$this->subject, $other] as $person) {
        $request = app(CreateDataRequest::class)->handle($person, (string) $person->uuid, DataRequestType::Erasure);
        FulfilDataRequestJob::dispatchSync((int) $request->getKey());
    }

    expect(User::query()->find($this->subject->getKey())?->email)
        ->not->toBe(User::query()->find($other->getKey())?->email);
});
