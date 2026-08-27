<?php

declare(strict_types=1);

use App\Modules\Learning\Actions\DecideTransferRequest;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Contracts\CohortDirectory;

/*
| SC-008ب · FR-028و — ⚠️ A PENDING REQUEST TOUCHES THE EXISTING MEMBERSHIP WITH
| NOTHING.
|
| Not a column, not a flag, not a "pending" state on the row. The student keeps
| their group, their timetable and their seats until somebody approves —
| otherwise they leave one place before entering another, waiting on an answer
| that may never come, and a teacher who simply never opens the queue has taken
| a course away by not doing anything.
*/

it('leaves the membership row byte-identical while a request waits', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    $before = CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->whereNull('closed_at')
        ->firstOrFail()
        ->getAttributes();

    app(RequestTransfer::class)->handle($fx['b'], $fx['student'], 'الأحد أنسب');

    $after = CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->whereNull('closed_at')
        ->firstOrFail()
        ->getAttributes();

    expect($after)->toEqual($before);

    expect(app(CohortDirectory::class)->openMembershipCohortId($fx['student'], (int) $fx['course']->getKey()))
        ->toBe((int) $fx['a']->getKey());

    // ⚠️ AND THE SEAT IS NOT CLAIMED IN ADVANCE. Measuring capacity at submission
    // is how two pending requests both get told yes about one place — FR-028ز
    // moves the measurement to the decision, and this is the half of it that
    // would silently stop being true if a claim crept in here.
    expect((int) $fx['b']->refresh()->members_count)->toBe(0);
});

it('refuses a second pending request in the same course', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    app(RequestTransfer::class)->handle($fx['b'], $fx['student']);

    expect(fn () => app(RequestTransfer::class)->handle($fx['b'], $fx['student']))
        ->toThrow(CohortRefusal::class);
});

it('leaves the membership alone when the request is rejected, and says why', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);

    $decided = app(DecideTransferRequest::class)
        ->handle($request, $fx['owner'], false, 'المجموعة مكتملة هذا الفصل.');

    expect($decided->status)->toBe(CohortTransferRequest::REJECTED);
    expect($decided->decision_reason)->toBe('المجموعة مكتملة هذا الفصل.');

    expect(app(CohortDirectory::class)->openMembershipCohortId($fx['student'], (int) $fx['course']->getKey()))
        ->toBe((int) $fx['a']->getKey());
});

/*
| ⚠️ AND A REJECTION WITHOUT A REASON IS REFUSED BEFORE ANYTHING IS WRITTEN
| (FR-028ح). A silent refusal reads as a fault and is submitted again for ever,
| which is a queue the teacher then has to clear twice.
*/
it('will not reject without a written reason, and leaves the request pending', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);

    expect(fn () => app(DecideTransferRequest::class)->handle($request, $fx['owner'], false, '   '))
        ->toThrow(CohortRefusal::class);

    expect($request->refresh()->status)->toBe(CohortTransferRequest::PENDING);
});
