<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/*
| SC-011 · FR-041 … FR-044 — the independent control.
|
| The document's own words: "لا تُفتح إطلاقاً وعلى الحساب رصيد مستحق، حتى لو كانت
| الحصص نفسها مفتوحة". Three claims, failing in three directions:
|
|   · the classified file is refused WITH the number and the way to pay, while the
|     ordinary file beside it opens — the control is on the asset, not the person;
|   · the paid-up course is untouched. Withholding is per COURSE, and answering per
|     workspace would close a course nobody owes a riyal on;
|   · the check runs at every issue, and the lift needs no job — the next attempt
|     after payment simply succeeds.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A deferring mode: the student has to be able to GO negative for any of this
    // to be reachable. In PREPAID_CREDITS the floor is zero and they simply run
    // out (FR-014), which is US5's wall, not this one.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);
});

/** Mark a lesson high value in the teacher's own context (FR-041). */
function classify(Lesson $lesson): Lesson
{
    app(WorkspaceContext::class)->forWorkspace(test()->workspace, function () use ($lesson): void {
        $lesson->forceFill(['is_high_value' => true])->save();
    });

    return $lesson->refresh();
}

/** Push this student's balance in this course below zero. */
function oweOn(object $course, object $student, int $credits = 2): void
{
    $balance = billingBalance(test()->workspace, $student, $course);

    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Consume,
        credits: -$credits,
        sourceType: 'test_debt',
        // Distinct per course, or the second call is read as a duplicate of the
        // first and writes nothing at all.
        sourceId: (int) $course->getKey(),
        enforceFloor: false,
    ));
}

it('refuses the classified file with the amount owed, and opens the ordinary one', function (): void {
    $notes = classify($this->lessonWithVideo($this->workspace));

    // Same course, same student, same entitlement — only the classification
    // differs, which is what makes this pair the assertion rather than either
    // half of it alone.
    $ordinary = $this->lessonWithVideo($this->workspace, $notes->course);

    [$student] = $this->enrolledViewer($this->workspace, $notes);

    oweOn($notes->course, $student);

    $refusal = $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")
        ->assertStatus(402)
        ->json();

    // FR-032's shape, carried by the refusal itself: the reason, the number, and
    // the course to buy for. A bare 403 would leave the student guessing at a
    // wall they can clear with one payment.
    expect($refusal['code'])->toBe('access_withheld')
        ->and($refusal['credits_needed'])->toBeGreaterThan(0)
        ->and($refusal['course'])->toBe($notes->course->uuid)
        ->and($refusal['message'])->toContain('موقوف');

    // And the ordinary lesson in the same course, for the same student, at the
    // same moment, opens. The account IS withheld — the control is on the asset,
    // not on the person, which is what «حتى لو كانت الحصص نفسها مفتوحة» means.
    expect(app(AccountStanding::class)->isWithheld($student, (int) $notes->course_id))->toBeTrue();

    $this->postJson("/api/v1/lessons/{$ordinary->uuid}/playback")->assertOk();
});

it('leaves the paid-up course open — the hold is by course, not by person', function (): void {
    $maths = $this->lessonWithVideo($this->workspace);
    $physics = classify($this->lessonWithVideo($this->workspace));

    [$student] = $this->enrolledViewer($this->workspace, classify($maths));
    $this->enrolledViewer($this->workspace, $physics, $student);

    // Owing on maths, paid up on physics.
    oweOn($maths->course, $student);
    grantCredits(billingBalance($this->workspace, $student, $physics->course), 4, 'physics-paid');

    $this->postJson("/api/v1/lessons/{$maths->uuid}/playback")->assertStatus(402);

    // ⚠️ THE ASSERTION A PER-WORKSPACE ANSWER FAILS. Summing the two balances
    // gives +2 and opens both; answering per workspace closes both. Neither is a
    // rounding error — one hands away the notes of a course that was never paid
    // for, the other punishes a teacher who was paid in full.
    $this->postJson("/api/v1/lessons/{$physics->uuid}/playback")->assertOk();
});

it('checks at every issue, not once (FR-043)', function (): void {
    $notes = classify($this->lessonWithVideo($this->workspace));

    [$student] = $this->enrolledViewer($this->workspace, $notes);

    grantCredits(billingBalance($this->workspace, $student, $notes->course), 2, 'first');

    // Paid up: the file opens.
    $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")->assertOk();

    oweOn($notes->course, $student, 4);

    // A check cached against the session, or made once at enrolment, would still
    // be answering September's question in December.
    $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")->assertStatus(402);
});

it('opens again on the very next attempt after payment, with no job (FR-044)', function (): void {
    $notes = classify($this->lessonWithVideo($this->workspace));

    [$student] = $this->enrolledViewer($this->workspace, $notes);

    oweOn($notes->course, $student);

    $needed = $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")
        ->assertStatus(402)
        ->json('credits_needed');

    grantCredits(billingBalance($this->workspace, $student, $notes->course), $needed, 'settle');

    // No sweep, no operator, no queue worker in between. The hold is DERIVED, so
    // there is nothing to lift — the predicate simply answers differently.
    $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")->assertOk();
});

it('vetoes a classified REVIEW RECORDING too, in the bulk path', function (): void {
    // The case that slips a filter written into the ordinary-lesson branch: a
    // recording is entitled by its SEAT, so it returns from its own branch before
    // any classification is looked at — and «تسجيلات المراجعة» is the spec's own
    // example of a high-value asset. The veto therefore runs after every branch.
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    $notes = classify($this->lessonWithVideo($this->workspace));
    [$student] = $this->enrolledViewer($this->workspace, $notes);

    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // Paid up first, because BookSeat refuses a withheld student (US5) — which is
    // also the honest sequence: you booked and attended, then fell behind.
    grantCredits(billingBalance($this->workspace, $student, $notes->course), 4, 'seat');

    $class = billableSession($this->workspace, $this->owner, $notes->course);
    app(BookSeat::class)->handle($class->refresh(), $student);

    /*
    | ⚠️ THE SEAT ALONE STOPPED BEING THE ENTITLEMENT WITH ٠٣٥ · FR-008. The
    | comment above says «you booked and attended» — this is the attending, and
    | before ٠٣٥ it was implicit in the booking. `credit_verdict_at` stamped is
    | «this seat was charged», which is what opens the hour; without it the first
    | assertion below refuses for a reason that has nothing to do with the money
    | veto this file exists to measure.
    */
    attendanceRow($this->workspace, $class->refresh(), $student, AttendanceStatus::Present);

    $recording = app(WorkspaceContext::class)->forWorkspace($this->workspace, fn (): Lesson => Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $notes->course_id,
        'section_id' => $notes->section_id,
        'chapter_id' => $notes->chapter_id,
        'class_session_id' => $class->getKey(),
        'type' => 'video',
        'is_high_value' => true,
    ]));

    $action = app(IssuePlaybackGrant::class);

    expect($action->mayWatchMany([$recording], $student))->toBe([(int) $recording->getKey() => true]);

    oweOn($notes->course, $student, 6);

    expect($action->mayWatchMany([$recording], $student))->toBe([(int) $recording->getKey() => false]);
});

it('lets the teacher classify their own content, and lift it again (FR-041)', function (): void {
    // Driven through the HTTP path a teacher actually uses, not by writing the
    // column: everything above classifies with forceFill, so without this the
    // authoring half of US7 could have been left unwired and every other test
    // would still be green.
    Sanctum::actingAs($this->owner);

    $course = billingCourse($this->workspace);

    $section = $this->postJson("/api/v1/courses/{$course->uuid}/sections", ['title' => 'مراجعة'])
        ->assertCreated()->json('uuid');

    $chapter = $this->postJson("/api/v1/courses/{$course->uuid}/chapters", [
        'section_uuid' => $section,
        'title' => 'النماذج',
    ])->assertCreated()->json('uuid');

    $lesson = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter,
        'title' => 'نموذج الإجابة',
        'type' => 'article',
        'content' => 'الحل',
        'is_high_value' => true,
    ])->assertCreated()->assertJsonPath('is_high_value', true)->json('uuid');

    // ⚠️ A TITLE EDIT MUST NOT CLEAR IT. This is the partial-update bug class the
    // nullable DTO field exists for — the shipped UI PUTs one field at a time, so
    // a flag written unconditionally is a flag every other save turns off. It cost
    // `is_preview` its meaning once already, and there it was real access.
    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson}", ['title' => 'نموذج الإجابة النهائي'])
        ->assertOk()
        ->assertJsonPath('is_high_value', true);

    // And the lift — scenario 3's second half. An explicit false is an
    // instruction, which is exactly what an omitted key is not.
    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson}", ['is_high_value' => false])
        ->assertOk()
        ->assertJsonPath('is_high_value', false);
});

/*
| ⛔ **الرفضُ يقرأُ الرصيدَ مرّةً واحدة، وكانَ يقرؤُه مرّتَين.**
|
| الحكمُ («محجوب؟») والرقمُ («كم حصّةً يحتاج؟») مشيةٌ واحدةٌ في
| `AccountStanding` — الحسابُ ثمّ الرصيدُ ثمّ مساحةُ العملِ ونافذةُ امتحاناتِها —
| و FR-032 يوجبُ أن تحملَ جملةُ الرفضِ الرقمَ، فهذا البابُ يسألُ النصفَينِ معاً
| في كلِّ رفض.
|
| **كيفَ يمسك**: أعِدْ `IssuePlaybackGrant` إلى `isWithheld()` ثمّ
| `creditsNeededFor()` ⇒ يسقطُ بـ«٢ بدلَ ١».
*/
it('reads the balance once to refuse a high-value file', function (): void {
    $notes = classify($this->lessonWithVideo($this->workspace));
    [$student] = $this->enrolledViewer($this->workspace, $notes);
    oweOn($notes->course, $student);

    // تحميةٌ: الإعداداتُ التشغيليّةُ صفوفٌ تُحفَظُ بعدَ أوّلِ قراءة.
    $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")->assertStatus(402);

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "credit_balances" where "student_credit_account_id"')) {
            $reads++;
        }
    });

    // الضابطُ الموجَب: الرفضُ ما زالَ رفضاً ويحملُ رقمَه — قراءةٌ واحدةٌ
    // تُرضيها نسخةٌ لا ترفضُ شيئاً إرضاءً تامّاً.
    $this->postJson("/api/v1/lessons/{$notes->uuid}/playback")
        ->assertStatus(402)
        ->assertJsonPath('code', 'access_withheld')
        ->assertJsonPath('credits_needed', fn (int $n): bool => $n > 0);

    expect($reads)->toBe(1);
});

it('never asks the contract from inside a Resource', function (): void {
    // A Resource runs once per row, so a call there is an N+1 by construction —
    // the reason the contract carries a bulk form at all. Swept across EVERY
    // module, not just Payments: Media is the one most tempted, because it is the
    // module that had to ask the question in the first place.
    $offenders = [];

    foreach (glob(base_path('app/Modules/*/Http/Resources/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, 'AccountStanding') || str_contains($source, 'isWithheld(')
            || str_contains($source, 'refusalFor(')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});
