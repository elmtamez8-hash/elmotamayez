<?php

declare(strict_types=1);

namespace App\Modules\Marketplace;

use App\Modules\Marketplace\Events\ComplaintConfirmed;
use App\Modules\Marketplace\Events\ReviewModerated;
use App\Modules\Marketplace\Events\ReviewSubmitted;
use App\Modules\Marketplace\Listeners\QueueTrustScoreRecalculation;
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
    }
}
