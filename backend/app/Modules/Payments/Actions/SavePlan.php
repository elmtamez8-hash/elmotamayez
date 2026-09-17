<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;

/**
 * A teacher writes a plan's duration and coverage (T091 · FR-025).
 *
 * ⚠️ THE PRICE IS REFUSED HERE, LOUDLY, RATHER THAN FILTERED OUT OF A FORM.
 * FR-025 (Q4) splits one row between two actors: a subscription is access to
 * TEACHING, so its price is the platform's — the same line 006 drew when it gave
 * `credit_packages` no price column at all. A form that simply omits the field
 * shapes one request and not the next, and the panel, the seeders and any future
 * importer reach this Action with no form behind them. So the field is refused
 * with a sentence when the writer does not hold the platform permission, and
 * `price_minor` is not `$fillable` besides — {@see SetPlanPrice} is the only
 * thing that writes it.
 *
 * Silently dropping it would be worse than refusing: a teacher who types 300 and
 * is told nothing believes they have set a price, and finds out when a student
 * cannot buy.
 */
class SavePlan extends Action
{
    public function __construct(private readonly CohortDirectory $cohorts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, int $workspaceId, array $data, ?Plan $plan = null): Plan
    {
        if (array_key_exists('price_minor', $data) && ! $author->can(Permissions::PLANS_PRICE)) {
            throw new DomainException('سعر الباقة تحدّده المنصّة، لا المدرّس.');
        }

        $coverage = $data['coverage_type'] instanceof PlanCoverage
            ? $data['coverage_type']
            : PlanCoverage::from((string) $data['coverage_type']);

        $sessionType = $data['session_type'] instanceof ClassSessionType
            ? $data['session_type']
            : ClassSessionType::from((string) $data['session_type']);

        [$duration, $sessionCount] = $this->resolveShape($data, $coverage, $sessionType);

        $plan ??= new Plan;

        $plan->fill([
            'workspace_id' => $workspaceId,
            'title' => (string) $data['title'],
            'duration_days' => $duration,
            'session_count' => $sessionCount,
            'session_type' => $sessionType,
            'coverage_type' => $coverage,
            'coverage_uuid' => $this->resolveCoverage($coverage, $data['coverage_uuid'] ?? null, $workspaceId),
            'currency' => (string) ($data['currency'] ?? 'QAR'),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $plan->save();

        return $plan;
    }

    /**
     * «One shape or the other — never both, never neither» (٠٣٦ · FR-020).
     *
     * ⛔ THE ACTION IS WHERE THIS LIVES, and the engine deliberately does not help.
     * A `CHECK` constraint is spelled differently on MySQL and SQLite, is invisible
     * to every test here, and tells the teacher nothing about WHICH field to fix.
     * The Action is also the one entrance the panel, the API and any seeder share.
     *
     * ⚠️ AND IT REPLACES `(int) $data['duration_days']`, WHICH WAS THE REAL DOOR.
     * That cast turned a missing duration into `0`, the line under it refused
     * anything below 1, and so a session-shaped plan was refused by the Action
     * itself — «مدّة الباقة يوم واحد على الأقل» about a field the teacher had
     * deliberately left empty.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveShape(array $data, PlanCoverage $coverage, ClassSessionType $sessionType): array
    {
        $duration = $this->positiveOrNull($data['duration_days'] ?? null);
        $sessionCount = $this->positiveOrNull($data['session_count'] ?? null);

        if ($duration !== null && $sessionCount !== null) {
            throw new DomainException('الباقة إمّا بمدّة وإمّا بعدد حصص، لا الاثنين معاً.');
        }

        if ($duration === null && $sessionCount === null) {
            throw new DomainException('حدّد مدّة الباقة أو عدد حصصها.');
        }

        /*
        | ⛔ FR-022 — SESSIONS NEED A COURSE TO HANG OFF. Workspace coverage names
        | no single course, so a balance of sessions bought under it has nothing to
        | be spent on: the row saves, the student pays, and no seat anywhere knows
        | about it.
        */
        if ($sessionCount !== null && $coverage === PlanCoverage::Workspace) {
            throw new DomainException('باقة الحصص تخصّ كورساً أو مجموعة، لا كلّ كورسات المدرّس.');
        }

        /*
        | ⛔ AND A GROUP PLAN OF THE PRIVATE KIND IS A PLAN THAT DISAPPEARS. The
        | bridge that decides which cohorts are priced filters on the group session
        | type, so a cohort-covered plan typed `individual` is written, saved,
        | listed on the teacher's own screen — and reaches no group at all, while
        | the group it was written for reads «no plan reaches it». Two screens
        | contradicting each other with nothing logged.
        */
        if ($coverage === PlanCoverage::Cohort && $sessionType !== ClassSessionType::Group) {
            throw new DomainException('باقة المجموعة لا تكون فرديّة.');
        }

        return [$duration, $sessionCount];
    }

    private function positiveOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number < 1 ? null : $number;
    }

    /**
     * ⚠️ THE COURSE IS RESOLVED INSIDE THE TEACHER'S OWN WORKSPACE, NEVER WITH
     * `exists:courses,uuid`. That rule is a raw query with no global scope on it,
     * so it answers yes for every course on the platform — and a plan pointing at
     * somebody else's course is a teacher selling a subscription to a colleague's
     * material.
     */
    private function resolveCoverage(PlanCoverage $coverage, mixed $uuid, int $workspaceId): ?string
    {
        /*
        | ⛔ `requiresUuid()`, NOT `needsCourse()` — AND THE RESTRUCTURE IS THE FIX,
        | not the rename. Read as «is this the Course case», a group plan fell into
        | the branch below and had its `coverage_uuid` NULLED on the way to the
        | database: saved, listed, and pointing at no group at all.
        */
        if (! $coverage->requiresUuid()) {
            // Cleared, not kept: a plan edited from one course to the whole
            // workspace that keeps its old `coverage_uuid` is a row whose two
            // columns disagree, and the reader that trusts the wrong one is
            // whichever gets written next.
            return null;
        }

        if (! is_string($uuid) || $uuid === '') {
            throw new DomainException($coverage === PlanCoverage::Cohort
                ? 'باقة المجموعة تحتاج تحديد المجموعة.'
                : 'باقة الكورس الواحد تحتاج تحديد الكورس.');
        }

        /*
        | A group plan names a COHORT, so it is proved against the group directory
        | rather than against `courses` — and `describeGroupCohort()` is already
        | filtered to group cohorts, which is what stops a plan being pointed at a
        | private 1:1 room. The workspace is pinned from the plan's own, because
        | that read is deliberately unscoped.
        */
        if ($coverage === PlanCoverage::Cohort) {
            $cohort = $this->cohorts->describeGroupCohort($uuid);

            if ($cohort === null || (int) $cohort['workspace_id'] !== $workspaceId) {
                throw new DomainException('هذه المجموعة غير موجودة عندك.');
            }

            return $uuid;
        }

        /*
        | AND IT DECLARES `withoutWorkspaceScope()` -- WHICH IS NOT A WIDENING,
        | BECAUSE THE EXPLICIT CONDITION BELOW IS THE GUARD. The scope adds a
        | SECOND workspace condition, taken from whoever is asking: on the
        | teacher's own door the two agree and it changes nothing, and on 034 .
        | FR-017's admin door they cannot agree -- a platform officer's context
        | falls back to `users.last_workspace_id`, so the statement ANDs their
        | own workspace onto a query about somebody else's course, matches zero
        | rows, and refuses EVERY teacher's course. The same shape spec 024 found
        | five layers of in the approval chain, and no one-workspace fixture can
        | see it.
        */
        $exists = Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $uuid)
            ->exists();

        if (! $exists) {
            throw new DomainException('هذا الكورس غير موجود عندك.');
        }

        return $uuid;
    }
}
