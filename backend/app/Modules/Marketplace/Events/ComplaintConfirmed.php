<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Events;

use App\Modules\Marketplace\Models\Complaint;
use Illuminate\Foundation\Events\Dispatchable;

class ComplaintConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Complaint $complaint) {}

    public function teacherProfileId(): int
    {
        return (int) $this->complaint->teacher_profile_id;
    }
}
