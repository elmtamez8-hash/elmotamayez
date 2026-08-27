<?php

declare(strict_types=1);

use App\Modules\Learning\Actions\ArchiveCohort;
use App\Modules\Learning\Actions\DecideTransferRequest;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RemoveMember;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Models\Enrollment;

/*
| SC-007 · FR-033 · FR-034 — the history.
|
| ⚠️ A REJECTION IS RECORDED EXACTLY AS AN APPROVAL IS. A log that keeps only
| what was accepted shows a student who asked three times and was refused as a
| student who never asked for anything — and the teacher who refused them has no
| record of having done so.
|
| ⚠️ AND THE REFUSAL TO EDIT LIVES ON THE MODEL, NOT ONLY IN THE ACTION. An
| Action guards the one door it is; `booted()` guards the seeder, the panel and
| whatever gets written next year.
*/

/** @return list<string> */
function eventsFor(int $studentId): array
{
    return CohortMembershipEvent::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $studentId)
        ->orderBy('id')
        ->pluck('event')
        ->all();
}

it('writes one row for every change, with the time, the actor and the reason', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student'], 'الأحد أنسب');
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], true, 'حسناً');

    expect(eventsFor((int) $fx['student']->getKey()))->toBe([
        CohortMembershipEvent::JOINED,
        CohortMembershipEvent::REQUESTED,
        // The move, then the decision that caused it. Both, because they answer
        // different questions: one is «where is this student now», the other is
        // «who let them».
        CohortMembershipEvent::TRANSFERRED,
        CohortMembershipEvent::APPROVED,
    ]);

    $transfer = CohortMembershipEvent::query()->withoutWorkspaceScope()
        ->where('event', CohortMembershipEvent::TRANSFERRED)->firstOrFail();

    expect($transfer->actor_user_id)->toBe($fx['owner']->getKey());
    expect((int) $transfer->from_cohort_id)->toBe((int) $fx['a']->getKey());
    expect((int) $transfer->cohort_id)->toBe((int) $fx['b']->getKey());
    expect($transfer->created_at)->not->toBeNull();
    expect($transfer->reason)->toBe('حسناً');
});

it('records a rejection as fully as an approval', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], false, 'ممتلئة');

    expect(eventsFor((int) $fx['student']->getKey()))->toContain(CohortMembershipEvent::REJECTED);

    $rejection = CohortMembershipEvent::query()->withoutWorkspaceScope()
        ->where('event', CohortMembershipEvent::REJECTED)->firstOrFail();

    expect($rejection->reason)->toBe('ممتلئة');
    expect($rejection->actor_user_id)->toBe($fx['owner']->getKey());
});

it('drops a pending request whose destination was archived, with the reason written down', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);

    app(ArchiveCohort::class)->handle($fx['b'], $fx['owner']);

    expect($request->refresh()->status)->toBe(CohortTransferRequest::DROPPED);
    expect($request->decision_reason)->not->toBeNull();

    expect(eventsFor((int) $fx['student']->getKey()))->toContain(CohortMembershipEvent::REQUEST_DROPPED);

    // ⚠️ AND THE MEMBERS STAY. Archiving says "nobody new, and it is over" — not
    // "everybody out". Closing their memberships here would strip a timetable
    // and a thread the instant a teacher tidied up last term.
    expect(CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->whereNull('closed_at')->count())->toBe(1);
});

it('records a removal and gives the seat back', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    expect((int) $fx['a']->refresh()->members_count)->toBe(1);

    $membership = CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->whereNull('closed_at')->firstOrFail();

    app(RemoveMember::class)->handle($membership, $fx['owner'], 'تكرار الغياب');

    expect(eventsFor((int) $fx['student']->getKey()))->toContain(CohortMembershipEvent::REMOVED);
    expect((int) $fx['a']->refresh()->members_count)->toBe(0);

    // ⚠️ AND THE ENROLMENT IS UNTOUCHED. The student paid for the COURSE; the
    // group is a timetable. Removing them from Saturday must not repossess what
    // they bought.
    expect(Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->where('status', 'active')->exists())->toBeTrue();
});

it('refuses to edit or delete a row of the history', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    $event = CohortMembershipEvent::query()->withoutWorkspaceScope()->firstOrFail();

    expect(fn () => $event->update(['reason' => 'أعِدْ كتابته']))->toThrow(RuntimeException::class);
    expect(fn () => $event->delete())->toThrow(RuntimeException::class);
});
