<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\Plan;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The subscription plans one teacher offers (FR-025).
 *
 * ⚠️ THE TEACHER IS NAMED BY A COURSE, NOT BY A WORKSPACE UUID, and that is
 * about what the STUDENT actually holds. Every payload a student already has —
 * their balances, their enrolments — carries a course uuid and none of them
 * carries a workspace uuid, because the raw tenant key does not travel. Asking
 * for one would mean adding it to a student-facing Resource, which is the leak
 * `StudentBalanceAllowlist` exists to refuse. `/billing/packages?course=` takes
 * the same identifier for the same reason.
 *
 * ⚠️ AND IT IS A QUERY VALUE, NEVER A PATH PARAMETER. `/{course}` resolves the
 * model before any guard runs, and `BelongsToWorkspace` protects nothing on a
 * student's path: a student is a member of no workspace, `WorkspaceContext::id()`
 * is null, and `WorkspaceScope::apply()` adds no condition at all.
 *
 * ⚠️ AND THIS IS ONLY ONE THIRD OF THE CATALOGUE A STUDENT SEES. «بالحصّة» and
 * «بعدد من الحصص» are `credit_packages`, priced per course by
 * {@see ListCreditPackages}; time is what this table sells. The two lists are
 * assembled beside each other on the screen and stay apart in the API, because
 * one of them is derived from a teacher's settlement rate and guarded by
 * participation, and this one is a number an officer typed and guarded by
 * nothing.
 */
class ListPlans extends Action
{
    /** @return Collection<int, Plan> */
    public function handle(string $courseUuid): Collection
    {
        $workspaceId = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $courseUuid)
            ->value('workspace_id');

        if ($workspaceId === null) {
            throw new DomainException('هذا الكورس غير موجود.');
        }

        return Plan::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->sellable()
            ->orderBy('duration_days')
            ->get();
    }
}
