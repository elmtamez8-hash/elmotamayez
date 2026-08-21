<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Events;

use App\Modules\Compliance\Models\TeacherOffboarding;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The exit is final: settled, notice served, access gone (FR-035 … FR-037).
 *
 * ⚠️ NOTHING HERE IS REVERSIBLE, which is why the Action that fires it claims the
 * row with a conditional UPDATE first. Two operators pressing complete together
 * would otherwise both pass the settlement read, both write, and both fan this
 * out — memberships ended twice, tokens killed twice, and no way back.
 *
 * @see TeacherOffboardingRequested
 */
class TeacherOffboardingCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TeacherOffboarding $offboarding) {}
}
