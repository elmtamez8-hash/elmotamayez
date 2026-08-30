<?php

declare(strict_types=1);

namespace App\Modules\Marketplace;

use App\Models\User;
use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Compliance\Events\TeacherOffboardingRequested;
use App\Modules\Marketplace\Console\BenchmarkMarketplace;
use App\Modules\Marketplace\Events\ComplaintConfirmed;
use App\Modules\Marketplace\Events\ReviewModerated;
use App\Modules\Marketplace\Events\ReviewSubmitted;
use App\Modules\Marketplace\Listeners\QueueTrustScoreRecalculation;
use App\Modules\Marketplace\Listeners\UnlistDepartedTeacher;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Marketplace\Support\MarketplacePersonalData;
use App\Modules\Marketplace\Support\SchoolYearDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class MarketplaceServiceProvider extends Module
{
    protected string $name = 'Marketplace';

    public function register(): void
    {
        parent::register();

        /*
        | ⚠️ `scoped()`, THE THIRD LIFETIME — not `bind()` and not `singleton()`.
        | The directory memoises a `slug ⇒ stage` map for the request, so `bind()`
        | would rebuild it several times inside one (the cost it exists to remove);
        | and a queue worker's container OUTLIVES the job, so a singleton would go
        | on serving a year an operator renamed or retired until the worker
        | restarts.
        */
        $this->app->scoped(SchoolYearDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([MarketplacePersonalData::class], 'compliance.personal_data');

        // Wired here, in the subscribing module, with Event::listen — there is no
        // EventServiceProvider in this codebase (Constitution III).
        foreach ([ReviewSubmitted::class, ReviewModerated::class, ComplaintConfirmed::class] as $event) {
            Event::listen($event, QueueTrustScoreRecalculation::class);
        }

        /*
        | Spec 013 · FR-035 — a departing teacher stops being advertised. BOTH
        | events: at REQUEST so the marketplace stops enrolling new students with
        | somebody who is leaving, and again at COMPLETION because that is where the
        | contract puts it. Unlisting twice is one idempotent UPDATE; listing again
        | is what nobody wants.
        */
        foreach ([TeacherOffboardingRequested::class, TeacherOffboardingCompleted::class] as $event) {
            Event::listen($event, UnlistDepartedTeacher::class);
        }

        /*
        | Registered explicitly because ONE policy serves TWO models — Laravel's
        | guesser would look for SubjectPolicy and GradeLevelPolicy and find
        | neither, which fails OPEN into "no policy applies" rather than into an
        | error anyone would notice.
        */
        Gate::policy(Subject::class, TaxonomyPolicy::class);
        Gate::policy(GradeLevel::class, TaxonomyPolicy::class);
        // Spec 011 · FR-042 — a third model for the same decision and the same
        // permission. See TaxonomyPolicy: one policy, because two files differing
        // only in a type-hint is two places for the next person to change one.
        Gate::policy(Region::class, TaxonomyPolicy::class);
        // Spec 022 · FR-011 — a fourth model, the same decision, the same
        // platform permission. Explicit for the reason above: `taxonomy.manage`
        // itself shipped in 009 declared, seeded and read by NO file, and a
        // guesser that fails open is what makes that invisible.
        Gate::policy(SchoolYear::class, TaxonomyPolicy::class);

        /*
        | The same seam the renamed teacher uses below, for the same reason.
        |
        | ⚠️ THE PUBLIC TAXONOMY IS CACHED, so an edit made in /admin would not
        | reach a visitor until the TTL lapsed — a subject retired because it is
        | wrong would keep being offered for up to a minute, and a corrected name
        | would look like a save that did not take. On the model rather than in the
        | Filament page: the panel is one writer and a seeder or a later endpoint
        | is another, and a stale marketplace is not a failure anybody would trace
        | back to a missing call.
        */
        Subject::saved(fn () => MarketplaceCache::flush());
        GradeLevel::saved(fn () => MarketplaceCache::flush());
        // ⚠️ REGION WAS MISSING FROM THIS LIST UNTIL SPEC 022, and the failure is
        // the sharpest of the four: `region_slug` is REQUIRED at registration and
        // validated against the live rows, so a region an operator retires stays
        // in the cached picker for up to a minute while the door already refuses
        // it — a 422 about an option the student is looking straight at.
        Region::saved(fn () => MarketplaceCache::flush());
        // Spec 022 — the fourth. A year added from the panel has to appear on the
        // NEXT request, not after the TTL: an operator who adds one and reloads
        // must see it, or the save reads as one that did not take.
        SchoolYear::saved(fn () => MarketplaceCache::flush());

        // Laravel only auto-discovers commands under app/Console/Commands, and a
        // module keeps its own; registering here is the module's job.
        if ($this->app->runningInConsole()) {
            $this->commands([BenchmarkMarketplace::class]);
        }

        // A renamed teacher must become findable under the new name. This is the
        // Marketplace maintaining its own denormalised column, not Identity
        // reaching across a module boundary — Identity does not know the column
        // exists (Constitution III).
        User::updated(function (User $user): void {
            if (! $user->wasChanged(['first_name', 'last_name'])) {
                return;
            }

            TeacherProfile::query()
                ->withoutWorkspaceScope()
                ->where('user_id', $user->getKey())
                ->each(function (TeacherProfile $profile) use ($user): void {
                    $profile->syncSearchName($user);
                    $profile->save();
                });

            MarketplaceCache::flush();
        });
    }
}
