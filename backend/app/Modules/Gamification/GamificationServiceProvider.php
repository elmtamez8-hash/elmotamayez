<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

use App\Modules\Assessments\Events\AttemptFinalized;
use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Assessments\Events\SubmissionGraded;
use App\Modules\Community\Events\HelpfulAnswerMarked;
use App\Modules\Gamification\Listeners\AwardOnAttemptFinalized;
use App\Modules\Gamification\Listeners\AwardOnAttendanceConfirmed;
use App\Modules\Gamification\Listeners\AwardOnHelpfulAnswer;
use App\Modules\Gamification\Listeners\AwardOnMistakeResolved;
use App\Modules\Gamification\Listeners\AwardOnSubmissionGraded;
use App\Modules\Gamification\Listeners\ReverseOnAttendanceOverridden;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\Level;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Gamification\Policies\CataloguePolicy;
use App\Modules\Gamification\Policies\RedemptionPolicy;
use App\Modules\Gamification\Policies\RewardPolicy;
use App\Modules\Gamification\Support\EloquentFocusState;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use App\Shared\Contracts\FocusState;
use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 009 — the gamification module.
 *
 * Auto-discovered by {@see ModulesServiceProvider}; never
 * register it by hand in bootstrap/providers.php.
 *
 * ⚠️ ZERO NEW EVENTS IN ANY EXISTING MODULE. This module listens to five events
 * that already ship (005 · 008) and emits three of its own that Notifications
 * subscribes to. It never calls an Action belonging to another module — the two
 * questions it cannot answer alone (who attended a session, is this student
 * frozen) are asked through contracts in App\Shared\Contracts.
 */
class GamificationServiceProvider extends Module
{
    protected string $name = 'Gamification';

    public function register(): void
    {
        parent::register();

        // Notifications asks "is this student focusing?" through the contract.
        // Gamification owns the table and binds the implementation — the same
        // shape as LiveSessions' SessionAttendanceDirectory.
        $this->app->bind(FocusState::class, EloquentFocusState::class);
    }

    public function boot(): void
    {
        parent::boot();

        // One policy for the three catalogue models: same question, same
        // permission, and three near-identical files is three places to change
        // two of.
        Gate::policy(GamificationAction::class, CataloguePolicy::class);
        Gate::policy(Level::class, CataloguePolicy::class);
        Gate::policy(Badge::class, CataloguePolicy::class);

        // The teacher's shop and its queue. Workspace-owned, so these are the
        // row-level half of a guard whose other half is the scope.
        Gate::policy(Reward::class, RewardPolicy::class);
        Gate::policy(Redemption::class, RedemptionPolicy::class);

        /*
        | ⚠️ FIVE EVENTS CONSUMED, AND NOT ONE OF THEM IS NEW. Every one already
        | shipped with 005 or 008 — `MistakeResolved` was even raised with no
        | listener on purpose, its docblock naming this phase.
        |
        | Wired here, in the SUBSCRIBING module, because there is no
        | EventServiceProvider in this product (Constitution III).
        |
        | ⚠️ TWO EVENTS ARE DELIBERATELY NOT WIRED, and their absence is a
        | decision rather than an omission:
        |
        | - `SessionCancelled` was the design's named trigger for reversal and is
        |   IMPOSSIBLE: attendance is confirmed at completion, and cancelling
        |   throws on a session in a final state. A session that has awarded
        |   anything can never be cancelled afterwards, so FR-010 had one route to
        |   it and that route could not be reached. `AttendanceOverridden` is the
        |   real moment an attendance award becomes false.
        |
        | - `AccessWithheld` also fires when a teacher OPENS AN EXAM WINDOW, and it
        |   fires per COURSE. Wiring it would dock a student experience because
        |   their teacher opened a window — three times over, for a student in
        |   three courses.
        */
        Event::listen(AttendanceConfirmed::class, AwardOnAttendanceConfirmed::class);
        Event::listen(AttendanceOverridden::class, ReverseOnAttendanceOverridden::class);
        Event::listen(AttemptFinalized::class, AwardOnAttemptFinalized::class);
        Event::listen(MistakeResolved::class, AwardOnMistakeResolved::class);
        Event::listen(SubmissionGraded::class, AwardOnSubmissionGraded::class);
        /*
        | Spec 010 — a teacher endorsed a student's answer in a public room.
        | Community fires it once, from the winner of its own conditional update;
        | the award key `(student, action, message)` is the layer beneath that.
        */
        Event::listen(HelpfulAnswerMarked::class, AwardOnHelpfulAnswer::class);
    }
}
