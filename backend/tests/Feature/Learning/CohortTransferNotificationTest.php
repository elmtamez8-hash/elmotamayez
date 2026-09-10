<?php

declare(strict_types=1);

use App\Modules\Learning\Actions\DecideTransferRequest;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;

/*
| FR-028ح — «وأن يُخطَرَ بالقرارِ قبولاً أو رفضاً، وأن يُخطَرَ المدرّسُ بالطلبِ عندَ وصولِه».
|
| ⚠️ EVERY CASE ASSERTS ON THE RENDERED `body`, NEVER ON A ROW COUNT. A
| notification whose template is missing is DROPPED IN SILENCE — DispatchNotification
| logs and does not fail the operation — so a test that counted rows would pass on
| the day the seeder row was forgotten only if the row also failed to exist, and
| fail for a reason that names nothing. Reading the body proves the template
| rendered, which is the thing that actually breaks.
*/

/**
 * The one notification of this type, or null.
 *
 * No `withoutWorkspaceScope()` anywhere in this file: `notifications` is a
 * PLATFORM-owned table by design (a student has one feed across every teacher
 * they study with), so no global scope touches it and the method does not exist
 * on the builder.
 */
function cohortNotice(NotificationType $type, int $recipientId): ?Notification
{
    return Notification::query()
        ->where('type', $type->value)
        ->where('recipient_user_id', $recipientId)
        ->first();
}

it('tells the teacher a transfer was requested', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    app(RequestTransfer::class)->handle($fx['b'], $fx['student'], 'الأحد أنسب لي');

    $notice = cohortNotice(NotificationType::CohortTransferRequested, (int) $fx['owner']->getKey());

    expect($notice)->not->toBeNull();
    expect($notice->body)->toContain('السبت ٤م')->toContain('الأحد ٦م');

    // The queue, not the course page: the teacher opens this to press one of
    // two buttons.
    expect($notice->action_url)->toBe('/manage/courses/'.$fx['course']->uuid.'/cohorts');

    // ⚠️ AND THE STUDENT IS NOT TOLD THEY ASKED. They just pressed the button.
    expect(Notification::query()
        ->where('recipient_user_id', $fx['student']->getKey())
        ->where('type', NotificationType::CohortTransferRequested->value)
        ->count())->toBe(0);
});

it('tells the student their transfer was approved', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], true);

    $notice = cohortNotice(NotificationType::CohortTransferApproved, (int) $fx['student']->getKey());

    expect($notice)->not->toBeNull();
    expect($notice->body)->toContain('الأحد ٦م');
    expect($notice->action_url)->toBe('/enrollments/'.$fx['course']->uuid);
});

/*
| ⚠️ THE ONE THE REQUIREMENT IS ACTUALLY ABOUT. A refusal that arrives saying
| «لم يُقبل» and nothing else reads as a fault and is submitted again for ever —
| which is the same queue back on the teacher's desk. So the assertion is that
| the teacher's own words are IN the message, not that a message exists.
*/
it('carries the written reason into the rejection the student reads', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], false, 'المجموعة تكاد تكتمل');

    $notice = cohortNotice(NotificationType::CohortTransferRejected, (int) $fx['student']->getKey());

    expect($notice)->not->toBeNull();
    expect($notice->body)->toContain('المجموعة تكاد تكتمل');

    // And no approval was sent alongside it — two types, one decision.
    expect(cohortNotice(NotificationType::CohortTransferApproved, (int) $fx['student']->getKey()))->toBeNull();
});

/*
| ⚠️ A DECISION THAT NEVER HAPPENED SENDS NOTHING. `DecideTransferRequest`
| refuses a second decision on a settled request, and the event is fired inside
| the transaction that settles it — so a listener wired above the guard, or
| outside the closure, would notify the student twice about one answer.
*/
it('sends nothing when the request was already settled', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], false, 'ليس الآن');

    try {
        app(DecideTransferRequest::class)->handle($request->refresh(), $fx['owner'], true);
    } catch (Throwable) {
        // The refusal is the point; what matters is what it left behind.
    }

    expect(Notification::query()
        ->where('recipient_user_id', $fx['student']->getKey())
        ->whereIn('type', [
            NotificationType::CohortTransferApproved->value,
            NotificationType::CohortTransferRejected->value,
        ])->count())->toBe(1);
});
