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

        $duration = (int) $data['duration_days'];

        if ($duration < 1) {
            throw new DomainException('مدّة الباقة يوم واحد على الأقل.');
        }

        $plan ??= new Plan;

        $plan->fill([
            'workspace_id' => $workspaceId,
            'title' => (string) $data['title'],
            'duration_days' => $duration,
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
     * ⚠️ THE COURSE IS RESOLVED INSIDE THE TEACHER'S OWN WORKSPACE, NEVER WITH
     * `exists:courses,uuid`. That rule is a raw query with no global scope on it,
     * so it answers yes for every course on the platform — and a plan pointing at
     * somebody else's course is a teacher selling a subscription to a colleague's
     * material.
     */
    private function resolveCoverage(PlanCoverage $coverage, mixed $uuid, int $workspaceId): ?string
    {
        if (! $coverage->needsCourse()) {
            // Cleared, not kept: a plan edited from one course to the whole
            // workspace that keeps its old `coverage_uuid` is a row whose two
            // columns disagree, and the reader that trusts the wrong one is
            // whichever gets written next.
            return null;
        }

        if (! is_string($uuid) || $uuid === '') {
            throw new DomainException('باقة الكورس الواحد تحتاج تحديد الكورس.');
        }

        $exists = Course::query()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $uuid)
            ->exists();

        if (! $exists) {
            throw new DomainException('هذا الكورس غير موجود في مساحتك.');
        }

        return $uuid;
    }
}
