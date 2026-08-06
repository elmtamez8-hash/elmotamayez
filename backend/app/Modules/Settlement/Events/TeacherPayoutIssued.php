<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

use App\Modules\Settlement\Models\TeacherPayout;
use Illuminate\Foundation\Events\Dispatchable;

/** Money left for the teacher. Notification here; the accounting books in 015. */
class TeacherPayoutIssued
{
    use Dispatchable;

    public function __construct(
        public readonly TeacherPayout $payout,
    ) {}
}
