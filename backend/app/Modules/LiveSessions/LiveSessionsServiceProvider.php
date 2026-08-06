<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Policies\AttendancePolicy;
use App\Modules\LiveSessions\Policies\ClassSessionPolicy;
use App\Modules\LiveSessions\Policies\FreezePeriodPolicy;
use App\Modules\LiveSessions\Policies\SessionBookingPolicy;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Shared\Modules\Module;
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
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ClassSession::class, ClassSessionPolicy::class);
        Gate::policy(SessionBooking::class, SessionBookingPolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(FreezePeriod::class, FreezePeriodPolicy::class);
    }
}
