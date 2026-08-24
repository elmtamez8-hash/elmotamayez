<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use App\Modules\Community\Support\CommunityPersonalData;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;

/*
| Community's half of the data-rights contract (013 · NFR-004).
|
| ⚠️ THIS MODULE WAS OUTSIDE THE MACHINERY FROM PHASE 1 AND THE GUARD REPORTED
| GREEN. `PersonalDataContractCoverageTest` detects a personal column by looking
| for `constrained('users')`, and every Community migration before the report
| card writes `unsignedBigInteger('sender_user_id')` — the other way this
| repository spells the same foreign key. So an erasure request completed green
| while leaving every private message with a minor exactly where it was. Both
| detectors are widened in the same change, and nothing else in the tree lit up.
|
| ⚠️ AND THE FIXTURE IS AGED BY QUERY, NEVER BY A `create()` ARRAY. `created_at`
| is not fillable, so a row aged inside the array is born today and every
| retention assertion over it passes against data too young to sweep — proving
| the opposite of what it claims. The 013 lesson, verbatim.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->student = User::factory()->create();

    $this->review = PeriodicReview::factory()->published()->create([
        'student_user_id' => $this->student->getKey(),
        'teacher_user_id' => $this->teacher->getKey(),
    ]);

    $this->card = ReportCard::factory()->published()->create([
        'student_user_id' => $this->student->getKey(),
    ]);

    ReportCardSegment::factory()->create([
        'report_card_id' => $this->card->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'teacher_user_id' => $this->teacher->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

/** Age rows by query — `created_at` is not fillable. */
function ageCommunityRows(string $table, string $when): void
{
    DB::table($table)->update(['created_at' => $when]);
}

it('exports every category it declares, including the empty ones', function (): void {
    $subject = new DataSubject($this->student);

    $yielded = [];

    foreach (app(CommunityPersonalData::class)->export($subject) as $category => $rows) {
        $yielded[$category] = array_merge($yielded[$category] ?? [], $rows);
    }

    // ⚠️ EVERY DECLARED CATEGORY APPEARS, even one with no rows. A missing file
    // is silence; «we hold nothing of this kind about you» is an answer, and the
    // difference is the whole point of a rights request.
    expect(array_keys($yielded))->toEqualCanonicalizing(
        app(CommunityPersonalData::class)->describe()
    );

    expect($yielded['periodic_review'])->toHaveCount(1);
    expect($yielded['report_card'])->toHaveCount(1);
    expect($yielded['report_card'][0]['segments'])->toHaveCount(1);
});

it('keeps grades away from a guardian entitled only to attendance', function (): void {
    // The guardian's granted scope IS the fourth argument — `null` there means
    // «the subject asked for their own data» and would hand over everything.
    $subject = new DataSubject($this->student, grantedScope: [GuardianPermission::Attendance]);

    $yielded = [];

    foreach (app(CommunityPersonalData::class)->export($subject) as $category => $rows) {
        $yielded[$category] = array_merge($yielded[$category] ?? [], $rows);
    }

    // The permission limits the CONTENT, not merely the entrance: the request was
    // opened legitimately and the categories still appear — empty.
    expect(array_keys($yielded))->toEqualCanonicalizing(
        app(CommunityPersonalData::class)->describe()
    );
    expect($yielded['periodic_review'])->toBe([]);
    expect($yielded['report_card'])->toBe([]);
    expect($yielded['chat_message'])->toBe([]);
});

it('erases everything it holds about one person', function (): void {
    $subject = new DataSubject($this->student);

    $owner = app(CommunityPersonalData::class);

    // The contract's fourth obligation: loop until the return is below the limit,
    // which is what makes a worker killed mid-walk resumable.
    do {
        $done = $owner->erase($subject, ErasureMode::Delete, 100);
    } while ($done >= 100);

    expect(PeriodicReview::withoutGlobalScopes()->where('student_user_id', $this->student->getKey())->count())
        ->toBe(0);
    expect(ReportCardSegment::withoutGlobalScopes()->where('student_user_id', $this->student->getKey())->count())
        ->toBe(0);
    expect(ReportCard::query()->where('student_user_id', $this->student->getKey())->count())
        ->toBe(0);
});

it('expires rows older than the retention window', function (): void {
    ageCommunityRows('periodic_reviews', '2020-01-01 00:00:00');
    ageCommunityRows('report_cards', '2020-01-01 00:00:00');
    ageCommunityRows('report_card_segments', '2020-01-01 00:00:00');

    $owner = app(CommunityPersonalData::class);
    $before = CarbonImmutable::parse('2021-01-01');

    $owner->expire('periodic_review', $before, ExpiryBehaviour::Delete, 100);
    $owner->expire('report_card', $before, ExpiryBehaviour::Delete, 100);

    expect(PeriodicReview::withoutGlobalScopes()->count())->toBe(0);
    expect(ReportCardSegment::withoutGlobalScopes()->count())->toBe(0);
    expect(ReportCard::query()->count())->toBe(0);
});

it('spares a subject under a legal hold', function (): void {
    ageCommunityRows('periodic_reviews', '2020-01-01 00:00:00');
    ageCommunityRows('report_cards', '2020-01-01 00:00:00');
    ageCommunityRows('report_card_segments', '2020-01-01 00:00:00');

    // ⚠️ RETENTION NEEDS NOBODY TO ASK, so a hold that only suspended erasure
    // REQUESTS would let this sweep delete the very rows a court ordered kept —
    // on a schedule, with the hold sitting green beside it.
    app(CommunityPersonalData::class)->expire(
        'report_card',
        CarbonImmutable::parse('2021-01-01'),
        ExpiryBehaviour::Delete,
        100,
        [(int) $this->student->getKey()],
    );

    expect(ReportCard::query()->count())->toBe(1);
    expect(ReportCardSegment::withoutGlobalScopes()->count())->toBe(1);
});

it('leaves a row younger than the window alone', function (): void {
    app(CommunityPersonalData::class)->expire(
        'report_card',
        CarbonImmutable::parse('2020-01-01'),
        ExpiryBehaviour::Delete,
        100,
    );

    expect(ReportCard::query()->count())->toBe(1);
});

it('sends a message nobody else wrote', function (): void {
    $other = User::factory()->create();

    Message::factory()->create([
        'sender_user_id' => $this->student->getKey(),
        'body' => 'MINE_SENTINEL',
    ]);

    Message::factory()->create([
        'sender_user_id' => $other->getKey(),
        'body' => 'THEIRS_SENTINEL',
    ]);

    $rows = [];

    foreach (app(CommunityPersonalData::class)->export(new DataSubject($this->student)) as $category => $page) {
        if ($category === 'chat_message') {
            $rows = array_merge($rows, $page);
        }
    }

    /*
    | ⚠️ ASCII SENTINELS, NEVER ARABIC. `getContent()` escapes non-ASCII, so an
    | Arabic needle is vacuously absent from any payload — every exposure test in
    | this product would pass over a response that leaked everything. The rule is
    | written down in `AssessmentExposureTest`; the same trap catches an export.
    */
    $bodies = array_column($rows, 'body');

    expect($bodies)->toContain('MINE_SENTINEL');
    expect($bodies)->not->toContain('THEIRS_SENTINEL');
});
