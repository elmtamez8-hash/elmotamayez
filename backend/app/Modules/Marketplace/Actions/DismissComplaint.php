<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\Complaint;
use App\Shared\Actions\Action;

class DismissComplaint extends Action
{
    /**
     * No event and no recalculation: a dismissed complaint never counted, so there
     * is nothing to take back. Only confirmation moves the score.
     */
    public function handle(Complaint $complaint): Complaint
    {
        $complaint->forceFill([
            'status' => Complaint::STATUS_DISMISSED,
            'confirmed_at' => null,
        ])->save();

        return $complaint;
    }
}
