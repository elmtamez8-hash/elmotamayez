<?php

declare(strict_types=1);

namespace App\Modules\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Console\BenchmarkMarketplace;
use App\Modules\Marketplace\Events\ComplaintConfirmed;
use App\Modules\Marketplace\Events\ReviewModerated;
use App\Modules\Marketplace\Events\ReviewSubmitted;
use App\Modules\Marketplace\Listeners\QueueTrustScoreRecalculation;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class MarketplaceServiceProvider extends Module
{
    protected string $name = 'Marketplace';

    public function boot(): void
    {
        parent::boot();

        // Wired here, in the subscribing module, with Event::listen — there is no
        // EventServiceProvider in this codebase (Constitution III).
        foreach ([ReviewSubmitted::class, ReviewModerated::class, ComplaintConfirmed::class] as $event) {
            Event::listen($event, QueueTrustScoreRecalculation::class);
        }

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
