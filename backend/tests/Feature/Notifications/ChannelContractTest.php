<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RegistersFakeChannels;

/**
 * The architectural claim of this whole spec, tested rather than asserted in a
 * design document: a channel is one class and one registration line.
 */
uses(RegistersFakeChannels::class);

function dispatchOf(User $user, NotificationType $type): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $user,
        type: $type,
        variables: [
            'name' => $user->name,
            'course_title' => 'الرياضيات',
            'certificate_number' => 'C-1',
            'listing_state' => 'ملفك ظاهر.',
            'reason' => 'سبب',
            'event' => 'حدث',
            'student_name' => 'سلمى',
            'session_title' => 'حصّة',
            'session_date' => 'الأحد',
            'amount' => '100 ر.ق',
            'starts_at' => 'غداً',
            'exam_title' => 'اختبار',
            'score' => '90',
            'note' => 'ملاحظة',
            'title' => 'حصّة الجبر',
            'status' => 'حاضر',
            'minutes' => '45',
            // Settlement (014). The bag has to satisfy EVERY template, because
            // TemplateRenderer refuses a missing variable and DispatchNotification
            // logs the refusal rather than failing — so a type whose variables
            // are absent here simply never arrives, and the count below is what
            // notices.
            'session_type' => 'فردية',
            'effective_from' => '2026-09-01',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-30',
            'units_count' => '12',
            'net' => '900 ر.ق',
            'reference' => 'TRF-1',
            // Credits (006). Exactly the mechanism the comment above describes:
            // without these three the four credit types render nothing, are
            // logged and dropped, and the count below came back 19 against 23.
            'course' => 'الرياضيات',
            'credits' => '3',
            'credits_needed' => '2',
            // And `months`, added with the dormancy notice. Without it that
            // template refuses to render, the notification is logged and
            // dropped, and the count below comes back one short — which is this
            // test doing its job, not a channel that failed.
            'months' => '12',
            // And `plan_title`, added with spec 011's subscription notice. The
            // same mechanism a fifth time — `teacher_name` and `ends_on` are
            // already above, so this one key is the whole difference between the
            // count reading 54 and reading 53.
            'plan_title' => 'اشتراك شهري',
            // And `lesson_title`, added with spec 032's broken-link report. The
            // same mechanism a sixth time — `course_title` is already above, so
            // this one key is the whole difference between the count reading 64
            // and reading 63. The test doing its job, not a channel that failed.
            'lesson_title' => 'الحصّة التعريفيّة',
            // And these three, added with spec 010's periodic assessment. The
            // same mechanism yet again: without them the template refuses to
            // render, the notification is logged and dropped, and the count comes
            // back 45 against 46 — this test doing its job.
            // (`student_name` is already above.)
            'teacher_name' => 'أستاذ خالد',
            // And these three, added with spec 027's subscription activation.
            // The same mechanism a seventh time, and it is the whole reason this
            // count is asserted rather than trusted: without them both new
            // templates refuse to render, both notifications are logged and
            // dropped, and the count came back 59 against 61 — a channel
            // contract that looked broken because a variable bag was short.
            'schedule' => 'مجموعة السبت — الأحد ٥م.',
            'next_session' => 'أقرب حصة: 2026-09-10.',
            'sessions' => 'حصّة الجبر (2026-09-10 17:00)',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            // And these four, added with spec 008's import report. Same mechanism
            // a third time: the template refuses to render without them, the
            // notification is logged and dropped, and the count comes back 29
            // against 30 — which is this test noticing a type that would have
            // silently reached nobody in production.
            // (`reason` is already above, and the failed-import template reuses it.)
            'filename' => 'questions.csv',
            'imported' => '990',
            'skipped' => '0',
            'failed' => '10',
            // And these three, added with spec 008's homework (US6). A FOURTH
            // time the same mechanism: without them the two assignment types
            // render nothing, are logged and dropped, and the count below came
            // back 32 against 34. `points` is the assignment's total and
            // ⚠️ `penalty_note` IS NOT AN EMPTY STRING, and that is not a
            // fixture detail: `missingVariables()` counts present-but-empty as
            // MISSING, so a listener that sent '' when nothing was deducted
            // would have its whole notification dropped — for exactly the
            // students who handed in on time. This line is why that was found.
            'assignment_title' => 'واجب الجبر',
            'points' => '10',
            'penalty_note' => 'لم يُخصم شيء للتأخير.',
            // A FIFTH time, with spec 009's two gamification types. The count
            // below came back 35 against 37 until these landed: a template whose
            // variables are absent renders nothing, is logged and dropped, and the
            // channel never sees it.
            'level_name' => 'متمكّن',
            'badge_name' => 'مواظب',
            /*
            | And these three, with spec 023's private session. The SAME
            | mechanism a sixth time, and CI is what found it: the four new types
            | rendered nothing, were logged and dropped, and the count came back
            | 55 against 59 — four notifications that would have reached nobody
            | in production while every scoped local run stayed green.
            |
            | (`course_title` and `student_name` are already above, and
            | `decision_reason` is the rejection's own — deliberately a different
            | key from `reason`, because a refusal the student READS may not share
            | a bag with an operational one.)
            */
            'session_time' => '2026-09-08 15:00',
            'duration' => '45',
            'decision_reason' => 'الموعد محجوز لطالب آخر.',
            // 049. Both ends of a postponement — the template says «من كذا إلى
            // كذا», so a bag with only one of them drops all three types in
            // silence and this test's count is the only thing that notices.
            'from_time' => '2026-10-10 16:00',
            'to_time' => '2026-10-11 18:00',
            'student_reason' => 'عندي امتحان ذلك اليوم.',
            'reward_title' => 'خصم على حصة',
            'teacher_name' => 'أ. خالد',
            // A SIXTH time, with spec 013's six data-protection types. The count
            // below came back 41 against 44 until these landed. The mechanism has
            // not changed once: a template whose variables are absent renders
            // nothing, `DispatchNotification` logs and does not fail, and the
            // channel never sees the message — so every assertion about it passes
            // by finding nothing.
            'request_type' => 'تصدير نسخة من البيانات',
            'due_date' => '٣٠ سبتمبر',
            'notice_end_date' => '٣٠ سبتمبر',
            // A SEVENTH time, with spec 010's `chat_message`. The count came back
            // 44 against 45 the moment the type landed — same mechanism, seventh
            // reading of it.
            'sender_name' => 'أ. خالد',
            // AN EIGHTH time, with spec 010's two announcement types. The count
            // came back 46 against 48 the moment they landed — the same mechanism
            // every time, and the reason this list is maintained by hand rather
            // than derived: a derivation would supply whatever the template asks
            // for and could never fail, which is precisely the failure it exists
            // to catch.
            'body' => 'حصة الغد الساعة الخامسة.',
            // A NINTH time, with spec 021's three cohort-transfer types. 48
            // against 51 the moment they landed — and the mechanism is worth
            // restating once more because it is the whole value of this list:
            // a missing variable makes the template render NOTHING,
            // DispatchNotification logs instead of failing, and the channel never
            // sees the message. Derive this map and it would supply whatever each
            // template asked for and could never fail again.
            'course_title' => 'الرياضيات',
            'from_cohort' => 'السبت ٤م',
            'to_cohort' => 'الأحد ٦م',
            'decision_reason' => 'المجموعة تكاد تكتمل',
            // A TENTH time, with spec 011's two store types. 51 against 53 the
            // moment they landed, and the mechanism has not changed once: a
            // template whose variable is missing renders NOTHING,
            // `DispatchNotification` logs it rather than failing the sale that
            // triggered it, and the channel never sees the message. In production
            // that is a parcel that reaches «في الطريق» with nobody told.
            'item_title' => 'مذكّرة المراجعة',
            'status' => 'في الطريق',
            /*
            | AN ELEVENTH time, with spec 011's `scheduled_report` — and this one
            | had been red on `main` since it landed (a588a97), 54 against 55,
            | found while shipping spec 012's push channel. The mechanism is the
            | same every time and worth the eleventh restatement precisely because
            | eleven readings did not stop the twelfth: a template whose variable
            | is missing renders NOTHING, `DispatchNotification` LOGS it rather
            | than failing the operation that triggered it, and the channel never
            | sees the message.
            */
            'period' => 'أغسطس ٢٠٢٦',
            'date' => '٣٠ أغسطس',
            'summary' => '١٢ حصّة · ٤ طلاب جدد',
            /*
            | A TWELFTH time, with spec 030's `guardian_link_requested`. 62 against
            | 63 the moment it landed, and the eleven restatements above did not
            | stop it — which is the argument for keeping this list HAND-WRITTEN.
            | Derive it and it would supply whatever each template asked for and
            | could never fail again, and the failure it catches is a real one: a
            | template whose variable is missing renders NOTHING,
            | `DispatchNotification` LOGS rather than failing the operation, and the
            | channel never sees the message. Here that is a child never told that
            | somebody asked to be their guardian — and a link nobody can settle.
            */
            'relation_type' => 'وليّ أمر',
        ],
    ));
}

// SC-001. Note what this test does NOT do: it changes no listener, no action and
// no notification type. If adding a channel required touching any of those, the
// test could not be written this way — which is exactly why it is the proof.
it('delivers every notification type to a newly registered channel', function (): void {
    $fake = $this->registerChannel();
    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    foreach (NotificationType::cases() as $type) {
        dispatchOf($user, $type);
    }

    expect($fake->count())->toBe(count(NotificationType::cases()));
});

// SC-003 — one channel throwing must not take the others down with it. Laravel's
// own notify() queues a single job for every channel, which is precisely the
// coupling FR-006 forbids.
it('keeps delivering on other channels when one fails', function (): void {
    $fake = $this->registerChannel();
    $fake->failWith = 'المزوّد لا يستجيب.';
    $fake->failPermanently = true;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    $deliveries = NotificationDelivery::query()->get()->keyBy('channel');

    expect($deliveries[NotificationChannel::InApp->value]->status)->toBe(DeliveryStatus::Delivered->value)
        ->and($deliveries[NotificationChannel::Email->value]->status)->toBe(DeliveryStatus::Failed->value);
});

// SC-004 / FR-007.
it('writes one notification record however many channels carry it', function (): void {
    $this->registerChannel();
    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(2);
});

it('skips a channel that is switched off without recording a failure', function (): void {
    $fake = $this->registerChannel();
    $fake->enabled = false;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    $delivery = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();

    // Skipped, not failed: an unconfigured channel is a state of the deployment,
    // and counting it as a failure would make the delivery log's failure rate
    // meaningless.
    expect($delivery->status)->toBe(DeliveryStatus::Skipped->value);
});

it('skips a channel that cannot reach this recipient', function (): void {
    $fake = $this->registerChannel();
    $fake->reachable = false;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    expect(NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole()->status)
        ->toBe(DeliveryStatus::Skipped->value);
});

// FR-008 / SC-005: nothing is delivered inline. A channel that hangs must not
// hold up the enrollment, payment or exam that triggered it.
it('queues delivery instead of sending inline', function (): void {
    Queue::fake();

    $this->registerChannel();
    $user = User::factory()->create();

    dispatchOf($user, NotificationType::EnrollmentCreated);

    Queue::assertPushed(DeliverNotificationJob::class);

    // The record exists immediately; the delivery has not run.
    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Queued->value);
});

it('records the notification even for a recipient who muted every channel', function (): void {
    $user = User::factory()->create();

    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::EnrollmentCreated->value,
        'channels' => [],
    ]);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    // "Do not push this at me" is not "pretend it never happened" — the feed is
    // where a notification lives.
    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});
