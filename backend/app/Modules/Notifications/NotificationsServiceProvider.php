<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Certificates\Events\CertificateRegenerated;
use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Events\TeacherChangesRequested;
use App\Modules\Marketplace\Events\TeacherRejected;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Channels\InAppChannel;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateIssued;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateRegenerated;
use App\Modules\Notifications\Listeners\NotifyStudentEnrolled;
use App\Modules\Notifications\Listeners\NotifyTeacherApproved;
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
        $this->app->tag([InAppChannel::class], 'notification.channels');

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
    }
}
