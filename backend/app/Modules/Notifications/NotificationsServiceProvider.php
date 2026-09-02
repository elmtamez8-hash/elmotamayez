<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Assessments\Events\AssignmentSubmitted;
use App\Modules\Assessments\Events\AttemptFinalized;
use App\Modules\Assessments\Events\AttemptPendingGrading;
use App\Modules\Assessments\Events\QuestionImported;
use App\Modules\Assessments\Events\SubmissionGraded;
use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Certificates\Events\CertificateRegenerated;
use App\Modules\Compliance\Events\TeacherOffboardingRequested;
use App\Modules\Gamification\Events\BadgeAwarded;
use App\Modules\Gamification\Events\LevelReachedUp;
use App\Modules\Gamification\Events\RewardRedeemed;
use App\Modules\Learning\Events\CohortTransferDecided;
use App\Modules\Learning\Events\CohortTransferRequested;
use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\LiveSessions\Events\PrivateSessionDecided;
use App\Modules\LiveSessions\Events\PrivateSessionExpired;
use App\Modules\LiveSessions\Events\PrivateSessionRequested;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Events\TeacherChangesRequested;
use App\Modules\Marketplace\Events\TeacherRejected;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Channels\InAppChannel;
use App\Modules\Notifications\Channels\WebPushChannel;
use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Listeners\NotifyImportReady;
use App\Modules\Notifications\Listeners\NotifyOffboardingStudents;
use App\Modules\Notifications\Listeners\NotifyOnRewardRedeemed;
use App\Modules\Notifications\Listeners\NotifyStudentBadgeAwarded;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateIssued;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateRegenerated;
use App\Modules\Notifications\Listeners\NotifyStudentCohortTransferDecided;
use App\Modules\Notifications\Listeners\NotifyStudentEnrolled;
use App\Modules\Notifications\Listeners\NotifyStudentExamResult;
use App\Modules\Notifications\Listeners\NotifyStudentGradingPending;
use App\Modules\Notifications\Listeners\NotifyStudentLevelUp;
use App\Modules\Notifications\Listeners\NotifyStudentPrivateSessionDecided;
use App\Modules\Notifications\Listeners\NotifyStudentPrivateSessionExpired;
use App\Modules\Notifications\Listeners\NotifyStudentSubmissionGraded;
use App\Modules\Notifications\Listeners\NotifyTeacherApproved;
use App\Modules\Notifications\Listeners\NotifyTeacherAssignmentSubmitted;
use App\Modules\Notifications\Listeners\NotifyTeacherChangesRequested;
use App\Modules\Notifications\Listeners\NotifyTeacherCohortTransferRequested;
use App\Modules\Notifications\Listeners\NotifyTeacherPrivateSessionRequested;
use App\Modules\Notifications\Listeners\NotifyTeacherRejected;
use App\Modules\Notifications\Support\NotificationsPersonalData;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Minishlink\WebPush\WebPush;

class NotificationsServiceProvider extends Module
{
    protected string $name = 'Notifications';

    public function register(): void
    {
        parent::register();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([NotificationsPersonalData::class], 'compliance.personal_data');

        // ── The one line a new channel adds ──────────────────────────────────
        // Add the class here, ship its templates, and every notification type in
        // the product — present and future — reaches it. Nothing in Actions/ or
        // Listeners/ changes; ChannelContractTest proves that with a fake channel
        // and ProviderAgnosticTest fails the build if anything starts naming a
        // provider (SC-001 · SC-002).
        // Spec 020 took that claim and cashed it. WhatsApp is this line plus one
        // class: not a listener, not an action, not a notification type moved.
        // Spec 012 · US2 cashed it a second time: web push is this line plus one
        // class. `NotificationType::defaultChannels()` is untouched — see that
        // class's docblock for why a channel must not switch itself on.
        $this->app->tag(
            [InAppChannel::class, WhatsAppChannel::class, WebPushChannel::class],
            'notification.channels',
        );

        /*
        | ⚠️ `bind`, NOT `singleton`, AND RESOLVED INSIDE `send()` RATHER THAN IN A
        | CONSTRUCTOR. `WebPush::__construct` validates the VAPID pair and
        | discovers a PSR-18 client, so building it eagerly would throw on every
        | deployment that has no keys — and `ChannelRegistry` constructs every
        | tagged channel the first time anything asks for one, which would take
        | the in-app bell down with it.
        |
        | ⚠️ AND THIS IS THE ONLY SEAM A TEST HAS. The library drives its own
        | PSR-18 client, so `Http::fake()` cannot see it and
        | `preventStrayRequests()` cannot catch it; a test substitutes the binding.
        */
        $this->app->bind(WebPush::class, fn (): WebPush => new WebPush([
            'VAPID' => [
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => (string) config('webpush.vapid.public'),
                'privateKey' => (string) config('webpush.vapid.private'),
            ],
        ]));

        $this->app->singleton(
            ChannelRegistry::class,
            fn ($app) => new ChannelRegistry($app->tagged('notification.channels')),
        );
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(EnrollmentCreated::class, NotifyStudentEnrolled::class);

        /*
        | Spec 013 · FR-033 — the students of a departing teacher, and their
        | guardians, are told with the date. At REQUEST: a notice sent when the exit
        | completes is not a notice.
        */
        Event::listen(TeacherOffboardingRequested::class, NotifyOffboardingStudents::class);
        Event::listen(CertificateIssued::class, NotifyStudentCertificateIssued::class);
        Event::listen(CertificateRegenerated::class, NotifyStudentCertificateRegenerated::class);

        // FR-017 — the applicant hears back on every review decision. Wired here,
        // in the subscribing module, because there is no EventServiceProvider
        // (Constitution III).
        Event::listen(TeacherApproved::class, NotifyTeacherApproved::class);
        Event::listen(TeacherRejected::class, NotifyTeacherRejected::class);
        Event::listen(TeacherChangesRequested::class, NotifyTeacherChangesRequested::class);

        // Spec 008 — the import runs off the request, so the teacher has closed
        // the tab. This notification is the only route back to the report naming
        // the rows that failed.
        Event::listen(QuestionImported::class, NotifyImportReady::class);

        /*
        | Spec 008 — the two halves of one sitting. `AttemptPendingGrading` says
        | the paper is in and unfinished, `AttemptFinalized` says the score is
        | final. Two events rather than one carrying a flag, because the listener
        | that must never treat the difference as optional is the certificate one
        | behind `ExamPassed`.
        */
        Event::listen(AttemptPendingGrading::class, NotifyStudentGradingPending::class);
        Event::listen(AttemptFinalized::class, NotifyStudentExamResult::class);

        /*
        | Homework (008 · US6). Two events, two recipients: the author hears that
        | work arrived, the student hears what it scored. One event with a flag
        | would let a student's preference silence the teacher's queue.
        */
        Event::listen(AssignmentSubmitted::class, NotifyTeacherAssignmentSubmitted::class);

        // 021 · FR-028ح — the transfer's two ends. The request goes to whoever
        // has to answer it; the answer goes back to whoever is waiting.
        Event::listen(CohortTransferRequested::class, NotifyTeacherCohortTransferRequested::class);
        Event::listen(CohortTransferDecided::class, NotifyStudentCohortTransferDecided::class);

        // The private session (023 · FR-018 · FR-023 · FR-027). Three listeners
        // and four types: the decision carries both of its answers, because they
        // differ only in which template renders.
        Event::listen(PrivateSessionRequested::class, NotifyTeacherPrivateSessionRequested::class);
        Event::listen(PrivateSessionDecided::class, NotifyStudentPrivateSessionDecided::class);
        Event::listen(PrivateSessionExpired::class, NotifyStudentPrivateSessionExpired::class);
        Event::listen(SubmissionGraded::class, NotifyStudentSubmissionGraded::class);

        /*
        | Spec 009 — the two pieces of good news. Both stay on the bell: several a
        | week for an engaged student is a daily congratulation on a guardian's
        | phone, and the predictable result is the guardian muting the number and
        | losing the attendance alert with it.
        */
        Event::listen(LevelReachedUp::class, NotifyStudentLevelUp::class);
        Event::listen(BadgeAwarded::class, NotifyStudentBadgeAwarded::class);

        // …and the one that does reach the guardian: a redeemed reward can be a
        // discount on a session, which changes what the family pays.
        Event::listen(RewardRedeemed::class, NotifyOnRewardRedeemed::class);
    }
}
