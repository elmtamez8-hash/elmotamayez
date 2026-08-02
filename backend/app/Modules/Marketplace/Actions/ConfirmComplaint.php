<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Events\ComplaintConfirmed;
use App\Modules\Marketplace\Models\Complaint;
use App\Shared\Actions\Action;

class ConfirmComplaint extends Action
{
    public function handle(Complaint $complaint): Complaint
    {
        $complaint->forceFill([
            'status' => Complaint::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ])->save();

        event(new ComplaintConfirmed($complaint));

        return $complaint;
    }
}
