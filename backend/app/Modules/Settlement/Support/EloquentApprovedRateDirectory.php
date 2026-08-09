<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Settlement\Actions\AccrueTeachingUnits;
use App\Shared\Contracts\ApprovedRateDirectory;
use App\Shared\Support\WorkspaceContext;
use DateTimeInterface;

/**
 * Settlement's answer to "what is one seat of this course worth?".
 *
 * Derives the resolver's inputs from the course the same way
 * {@see AccrueTeachingUnits} derives them from a
 * session, and hands them to the same {@see RateResolver}. Two derivations that
 * agreed by inspection would stop agreeing at the first grade-specific rate.
 */
class EloquentApprovedRateDirectory implements ApprovedRateDirectory
{
    /**
     * One answer per (course, type, moment) for the life of this instance.
     *
     * The six packages a teacher sells differ in SIZE, and size is not an input
     * to the price of one seat — so pricing six packages asks two questions
     * (individual, group), not six.
     *
     * Bound with bind(), not singleton(), so the memo dies with the request. A
     * singleton's memo outlives a Horizon job and would keep quoting yesterday's
     * rate for as long as the worker lived.
     *
     * @var array<string, ?int>
     */
    private array $memo = [];

    public function __construct(
        private readonly RateResolver $resolver,
        private readonly WorkspaceContext $context,
    ) {}

    public function approvedRateMinorForCourse(
        int $courseId,
        ClassSessionType $type,
        DateTimeInterface $moment,
    ): ?int {
        $key = $courseId.'|'.$type->value.'|'.$moment->format('U');

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->lookUp($courseId, $type, $moment);
    }

    private function lookUp(int $courseId, ClassSessionType $type, DateTimeInterface $moment): ?int
    {
        // The course read bypasses the workspace scope DELIBERATELY. A student
        // studying with three teachers has one current workspace, so a course
        // belonging to any of the other two would resolve to null under the
        // scope — read as "no approved rate", shown as an empty package list,
        // with no error anywhere. The bypass is safe because nothing about the
        // course is returned: the answer is one integer, and the caller already
        // holds the course id.
        $course = Course::query()->withoutWorkspaceScope()->find($courseId);

        if ($course === null || $course->teacher_profile_id === null) {
            return null;
        }

        // And the RATE read is wrapped, for the opposite reason. The primary
        // caller is a queued listener, where WorkspaceContext::id() is null and
        // WorkspaceScope adds no condition at all — so `settlement_rates` would
        // be searched across every workspace on the platform, and the first
        // teacher to approve a rate would price everyone's packages. forWorkspace
        // rather than set(): a worker that leaks its workspace poisons whatever
        // it handles next.
        return $this->context->forWorkspace(
            (int) $course->workspace_id,
            fn (): ?int => $this->resolver->resolve(
                (int) $course->teacher_profile_id,
                $type,
                $moment,
                $course->subject_id === null ? null : (int) $course->subject_id,
                $course->grade_level,
            )?->amount_minor,
        );
    }
}
