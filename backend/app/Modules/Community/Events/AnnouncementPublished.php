<?php

declare(strict_types=1);

namespace App\Modules\Community\Events;

use App\Modules\Community\Models\Announcement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notice became visible to the students in its scope (FR-042).
 *
 * Fired by the CLAIMANT of `published_at` alone — the one runner whose conditional
 * UPDATE changed a row. Fired after a read-then-write instead, two taps start two
 * fan-outs over three hundred students, and the diff that makes the fan-out
 * idempotent is racing itself rather than protecting anybody.
 */
class AnnouncementPublished
{
    use Dispatchable;

    public function __construct(public readonly Announcement $announcement) {}
}
