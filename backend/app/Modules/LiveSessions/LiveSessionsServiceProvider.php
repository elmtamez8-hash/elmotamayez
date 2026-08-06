<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Events\SessionCompleted;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Listeners\NotifySeatHolders;
use App\Modules\LiveSessions\Listeners\PublishRecordingAsLesson;
use App\Modules\LiveSessions\Listeners\SendAttendanceCorrection;
use App\Modules\LiveSessions\Listeners\UpdateTeacherCounters;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Policies\AttendancePolicy;
use App\Modules\LiveSessions\Policies\ClassSessionPolicy;
use App\Modules\LiveSessions\Policies\FreezePeriodPolicy;
use App\Modules\LiveSessions\Policies\SessionBookingPolicy;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\LiveSessions\Support\EloquentSessionAttendanceDirectory;
use App\Modules\Media\Events\MediaAssetReady;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class LiveSessionsServiceProvider extends Module
{
    protected string $name = 'LiveSessions';

    public function register(): void
    {
        parent::register();

        /*
         * The inversion point. Adding a commercial broadcast provider is a file
         * in Providers/ and a case here — nothing in Actions/, Models/ or the
         * frontend changes. Same shape as VideoProviderInterface (004) and
         * PaymentProviderInterface, both of which shipped with one implementation.
         *
         * Attendance deliberately does not pass through this interface at all
         * (research §R3), which is why the whole of user story 3 is buildable and
         * provable before any contract is signed.
         */
        $this->app->bind(BroadcastProviderInterface::class, fn (): BroadcastProviderInterface => match ((string) config('sessions.provider')) {
            default => $this->app->make(NullBroadcastProvider::class),
        });

        // LiveSessions owns the booking; Media asks through the interface rather
        // than reaching into these models. Same binding shape as Learning's
        // EnrollmentDirectory.
        $this->app->bind(SessionAttendanceDirectory::class, EloquentSessionAttendanceDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ClassSession::class, ClassSessionPolicy::class);
        Gate::policy(SessionBooking::class, SessionBookingPolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(FreezePeriod::class, FreezePeriodPolicy::class);

        // Cross-module integration is by event, never by calling into another
        // module's actions (Constitution III). Marketplace owns teacher_profiles;
        // this module only announces that a session ended.
        Event::listen(SessionCompleted::class, UpdateTeacherCounters::class);

        // Media announces a ready asset; this module decides that one of them is
        // a session recording and publishes it. Media knows nothing about
        // sessions, which is what Constitution III asks for.
        Event::listen(MediaAssetReady::class, PublishRecordingAsLesson::class);

        // A seat that will not be honoured is explained to the person who took
        // it — whether the teacher called the session off or a freeze suspended
        // it (FR-006 · FR-040).
        Event::listen(SessionCancelled::class, NotifySeatHolders::class);

        // A report already in a guardian's hands is corrected rather than left
        // standing (FR-037).
        Event::listen(AttendanceOverridden::class, SendAttendanceCorrection::class);

        // Ingest starts when the session closes, not when the room does: the
        // provider needs the session over before it has anything to hand back.
        Event::listen(SessionCompleted::class, function (SessionCompleted $event): void {
            IngestSessionRecordingJob::dispatch((int) $event->session->getKey());
        });
    }
}
