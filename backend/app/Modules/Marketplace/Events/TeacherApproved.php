<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Events;

use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Foundation\Events\Dispatchable;

class TeacherApproved
{
    use Dispatchable;

    public function __construct(public readonly TeacherProfile $profile) {}
}
