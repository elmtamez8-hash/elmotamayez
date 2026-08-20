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
use App\Modules\Gamification\Events\BadgeAwarded;
use App\Modules\Gamification\Events\LevelReachedUp;
use App\Modules\Gamification\Events\RewardRedeemed;
use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Events\TeacherChangesRequested;
use App\Modules\Marketplace\Events\TeacherRejected;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Channels\InAppChannel;
use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Listeners\NotifyImportReady;
use App\Modules\Notifications\Listeners\NotifyOnRewardRedeemed;
use App\Modules\Notifications\Listeners\NotifyStudentBadgeAwarded;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateIssued;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateRegenerated;
use App\Modules\Notifications\Listeners\NotifyStudentEnrolled;
use App\Modules\Notifications\Listeners\NotifyStudentExamResult;
use App\Modules\Notifications\Listeners\NotifyStudentGradingPending;
use App\Modules\Notifications\Listeners\NotifyStudentLevelUp;
use App\Modules\Notifications\Listeners\NotifyStudentSubmissionGraded;
use App\Modules\Notifications\Listeners\NotifyTeacherApproved;
use App\Modules\Notifications\Listeners\NotifyTeacherAssignmentSubmitted;
use App\Modules\Notifications\Listeners\NotifyTeacherChangesRequested;
use App\Modules\Notifications\Listeners\NotifyTeacherRejected;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class NotificationsServiceProvider extends Module
{
    protected string $name = 'Notifications';

    public function register(): void
    {
        parent::register();

        // ── The one line a new channel adds ──────────────────────────────────
        // Add the class here, ship its templates, and every notification type in
        // the product — present and future — reaches it. Nothing in Actions/ or
        // Listeners/ changes; ChannelContractTest proves that with a fake channel
        // and ProviderAgnosticTest fails the build if anything starts naming a
        // provider (SC-001 · SC-002).
        // Spec 020 took that claim and cashed it. WhatsApp is this line plus one
        // class: not a listener, not an action, not a notification type moved.
        $this->app->tag([InAppChannel::class, WhatsAppChannel::class], 'notification.channels');

        $this->app->singleton(
            ChannelRegistry::class,
            fn ($app) => new ChannelRegistry($app->tagged('notification.channels')),
        );
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(EnrollmentCreated::class, NotifyStudentEnrolled::class);
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
