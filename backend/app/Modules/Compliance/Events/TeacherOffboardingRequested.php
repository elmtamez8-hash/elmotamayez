<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Events;

use App\Modules\Compliance\Models\TeacherOffboarding;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A teacher has asked to wind down (spec 013 · FR-033).
 *
 * ⚠️ TWO EVENTS RATHER THAN ONE ACTION THAT KNOWS FIVE CONTEXTS. Everything an
 * exit touches — the public listing, the workspace memberships, the tokens, the
 * recordings' retention, the students' notice — lives in a different module, and
 * an `ExecuteTeacherOffboarding` that named all five is Constitution III broken
 * in one file.
 *
 * ⚠️ AND THE SPLIT BETWEEN THE TWO IS NOT COSMETIC: what fires HERE must be
 * survivable by a teacher who is still teaching. The notice period exists so
 * their students can finish; revoking their tokens or ending their membership at
 * REQUEST would lock them out of the lessons they announced they would deliver.
 * Only the announcement and the unlisting belong here.
 *
 * @see TeacherOffboardingCompleted
 */
class TeacherOffboardingRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TeacherOffboarding $offboarding) {}
}
