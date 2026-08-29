<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Modules\Analytics\Support\AnalyticsPersonalData;
use App\Shared\Modules\Module;

/**
 * Platform analytics (spec 011 · US6).
 *
 * ⚠️ ONE TAGGED LINE REGISTERS THE DATA-RIGHTS CONTRACT, and `Compliance` never
 * names a table of ours. It ships in the same change as `report_subscriptions`,
 * which is the migration that made this module hold a personal column at all —
 * written earlier it would have been three methods returning nothing, which is
 * the shape of guard this repository has already recorded twice.
 */
class AnalyticsServiceProvider extends Module
{
    protected string $name = 'Analytics';

    public function register(): void
    {
        parent::register();

        $this->app->tag([AnalyticsPersonalData::class], 'compliance.personal_data');
    }
}
