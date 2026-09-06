<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\PlanCoverage;
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
    /**
     * @param  string|null  $sessionType  narrows the list to one room size (027 · FR-008)
     * @return Collection<int, Plan>
     */
    public function handle(string $courseUuid, ?string $sessionType = null): Collection
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
            /*
            | ⚠️ THE WORKSPACE IS NOT THE COVERAGE, AND READING IT AS ONE OFFERED
            | EVERY PLAN ON EVERY COURSE THE TEACHER HAS. A plan scoped to «الكيمياء»
            | appeared on «التفاضل» — and the two modes failed differently, which
            | is why one filter has to close both:
            |
            |   • a GROUP choice was refused at the door with «هذه المجموعة لم تعد
            |     متاحة للانضمام» (`PurchaseSubscription::resolveCohort()` compares
            |     the group's course against the plan's coverage) — a sentence
            |     about the group, when the group was never the problem;
            |   • a PRIVATE choice was accepted. Nothing sends the course on that
            |     path — the plan's own coverage IS the course — so the buyer read
            |     «الكورس: التفاضل» on the screen and bought a month of «الكيمياء»,
            |     silently.
            |
            | Grouped, or the OR escapes `sellable()` and the workspace above it.
            */
            ->where(function ($query) use ($courseUuid): void {
                $query->where('coverage_type', PlanCoverage::Workspace->value)
                    ->orWhere(fn ($nested) => $nested
                        ->where('coverage_type', PlanCoverage::Course->value)
                        ->where('coverage_uuid', $courseUuid));
            })
            /*
            | ⚠️ DISPLAY, NOT PROTECTION. The one subscription screen shows only
            | the plans that match what the buyer chose, so a group choice is not
            | offered a one-to-one price — but the real guard is
            | `PurchaseSubscription::guardModeMatchesPlan()`, which sees the plan
            | the order is actually written against. A filter here alone would be
            | a rule enforced by a dropdown.
            */
            ->when($sessionType !== null, fn ($query) => $query->where('session_type', $sessionType))
            ->orderBy('duration_days')
            ->get();
    }
}
