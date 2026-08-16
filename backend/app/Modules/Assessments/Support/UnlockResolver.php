<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Models\UnlockExemption;
use App\Modules\Assessments\Models\UnlockRule;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Has this student earned the next session? (FR-036 → FR-042)
 *
 * ⚠️ EVERYTHING HERE IS BULK, AND THAT IS NOT AN OPTIMISATION. FR-041 checks the
 * condition at every access, and a timetable is twenty sessions — asked one at a
 * time this is 120 queries inside a Resource, which is the defect
 * `QueryBudgetTest` was written for. Five queries answer twenty sessions or one.
 *
 * ⚠️ AND THE HOMEWORK COMPONENT READS `submissions`, NEVER AN EXAM. FR-052 names
 * that prohibition outright: a gate that consulted `ExamGateSatisfaction` would
 * be asking a different question with a similar-looking answer, and a course
 * with no exam would silently never open.
 */
class UnlockResolver
{
    public function __construct(
        private readonly SessionAttendanceDirectory $attendance,
    ) {}

    /**
     * @param  list<int>  $classSessionIds
     * @return array<int, UnlockVerdict> keyed by session id
     */
    public function resolve(User $student, array $classSessionIds): array
    {
        if ($classSessionIds === []) {
            return [];
        }

        $sessions = DB::table('class_sessions')
            ->whereIn('id', $classSessionIds)
            ->get(['id', 'workspace_id', 'course_id']);

        if ($sessions->isEmpty()) {
            return [];
        }

        $rules = $this->rulesFor($sessions);

        /*
        | ⚠️ NOTHING ELSE IS ASKED WHEN NOTHING IS CONDITIONED, and that is the
        | ordinary case rather than an optimisation for a corner: FR-037 makes
        | the rule row optional, so every workspace that has not configured one
        | — which is all of them on the day this ships — would otherwise pay six
        | queries per page for an answer that is always "open".
        |
        | And each component pays only for itself below: a rule that asks for
        | attendance alone must not cost the two homework queries. This is what
        | keeps the whole gate inside SC-013's budget on a real endpoint, where
        | the page's own reads have already spent most of it.
        */
        $conditioning = $this->conditioningComponents($rules);

        if ($conditioning === []) {
            $out = [];

            foreach ($sessions as $session) {
                $out[(int) $session->id] = UnlockVerdict::open();
            }

            return $out;
        }

        $exempt = $this->exemptSessionIds($student, $classSessionIds);
        $previous = $this->attendance->previousCountableSessionIds($classSessionIds);

        $previousIds = array_values(array_filter(array_map(
            static fn (mixed $id): ?int => $id === null ? null : (int) $id,
            $previous,
        ), static fn (?int $id): bool => $id !== null));

        $attended = [];

        if ($previousIds !== [] && in_array('attendance', $conditioning, true)) {
            foreach ($this->attendance->attendedSessionIds($student, $previousIds) as $id) {
                $attended[$id] = true;
            }
        }

        $homework = in_array('assignment', $conditioning, true)
            ? $this->homeworkFor($student, $previousIds)
            : [];

        $out = [];

        foreach ($sessions as $session) {
            $sessionId = (int) $session->id;

            $out[$sessionId] = $this->verdictFor(
                $sessionId,
                $session->course_id === null ? null : (int) $session->course_id,
                $rules[(int) $session->workspace_id] ?? [],
                isset($exempt[$sessionId]),
                $previous[$sessionId] ?? null,
                $attended,
                $homework,
            );
        }

        return $out;
    }

    /**
     * @param  array<int, UnlockRule>  $workspaceRules  keyed by course id (0 = default)
     * @param  array<int, true>  $attended
     * @param  array<int, array{submitted: bool, score: float|null, points: int}>  $homework
     */
    private function verdictFor(
        int $sessionId,
        ?int $courseId,
        array $workspaceRules,
        bool $exempt,
        ?int $previousId,
        array $attended,
        array $homework,
    ): UnlockVerdict {
        /*
        | ⚠️ THE SPECIFIC ROW WINS OUTRIGHT AND NOTHING IS MERGED (FR-037). A
        | course rule that switches attendance OFF must switch it off even when
        | the workspace default has it on — merged, "this course does not require
        | attendance" would be impossible to say. And the ABSENCE of a course row
        | falls back to the default; it is never read as "no condition here",
        | which would make every unconfigured course a hole in the gate.
        */
        $rule = ($courseId !== null ? ($workspaceRules[$courseId] ?? null) : null);
        $scope = $rule !== null ? 'course' : 'default';

        $rule ??= $workspaceRules[UnlockRule::DEFAULT_SCOPE] ?? null;

        // No rule at all is the state every workspace is in on day one. FR-037
        // makes the default row optional, so its absence is "no condition",
        // never "blocked until configured".
        if ($rule === null || ! $rule->conditions()) {
            return UnlockVerdict::open($rule === null ? 'none' : $scope);
        }

        // FR-040 — the teacher's own override, and it beats every component.
        if ($exempt) {
            return UnlockVerdict::open($scope, exempt: true);
        }

        /*
        | ⚠️ NO PREVIOUS SESSION MEANS OPEN. The first class of a course has
        | nothing behind it, and a session whose predecessors were all cancelled
        | is in the same position — gating on a class that never happened would
        | shut a course nobody could ever enter. Advance booking depends on this
        | branch too: a session scheduled for next month has no attended
        | predecessor because the predecessor has not been taught yet.
        */
        if ($previousId === null) {
            return UnlockVerdict::open($scope);
        }

        $missing = [];

        if ($rule->requires_attendance && ! isset($attended[$previousId])) {
            $missing[] = 'attendance';
        }

        if ($rule->requires_assignment) {
            $work = $homework[$previousId] ?? null;

            // FR-042 — an unpublished assignment blocks nothing, so a session
            // whose homework is still a draft has no `work` row at all here.
            if ($work !== null) {
                if (! $work['submitted']) {
                    $missing[] = 'assignment';
                } elseif ($this->belowThreshold($work, (float) $rule->min_score_pct)) {
                    $missing[] = 'score';
                }
            }
        }

        if ($missing === []) {
            return UnlockVerdict::open($scope);
        }

        return UnlockVerdict::shut($scope, $missing, $this->sentence($missing, (float) $rule->min_score_pct));
    }

    /**
     * ⚠️ SUBMITTED-BUT-UNMARKED SATISFIES THE THRESHOLD UNTIL A GRADE EXISTS.
     * The student has done everything asked of them; blocking them makes the
     * teacher's marking queue into a gate on the whole class — the person who is
     * late is the teacher, and the person shut out is not. Once a grade is
     * recorded, the score speaks.
     *
     * @param  array{submitted: bool, score: float|null, points: int}  $work
     */
    private function belowThreshold(array $work, float $minPct): bool
    {
        if ($minPct <= 0 || $work['score'] === null || $work['points'] <= 0) {
            return false;
        }

        return ($work['score'] / $work['points']) * 100 < $minPct;
    }

    /** @param  list<string>  $missing */
    private function sentence(array $missing, float $minPct): string
    {
        $parts = [];

        if (in_array('attendance', $missing, true)) {
            $parts[] = 'حضور الحصة السابقة';
        }

        if (in_array('assignment', $missing, true)) {
            $parts[] = 'تسليم واجبها';
        }

        if (in_array('score', $missing, true)) {
            $parts[] = 'الوصول إلى '.rtrim(rtrim(number_format($minPct, 2, '.', ''), '0'), '.').'٪ في واجبها';
        }

        // ⚠️ NAMED, NEVER «غير متاح» (FR-038). A block with no stated cause turns
        // a motivation feature into an outage the student emails about — and the
        // one thing they cannot work out for themselves is what to go and do.
        return 'لفتح هذه الحصة ينقصك: '.implode(' و', $parts).'.';
    }

    /**
     * Which components any live rule actually asks for.
     *
     * @param  array<int, array<int, UnlockRule>>  $rules
     * @return list<string>
     */
    private function conditioningComponents(array $rules): array
    {
        $components = [];

        foreach ($rules as $byCourse) {
            foreach ($byCourse as $rule) {
                if (! $rule->conditions()) {
                    continue;
                }

                if ($rule->requires_attendance) {
                    $components['attendance'] = true;
                }

                if ($rule->requires_assignment) {
                    $components['assignment'] = true;
                }
            }
        }

        return array_keys($components);
    }

    /**
     * @param  Collection<int, \stdClass>  $sessions
     * @return array<int, array<int, UnlockRule>>
     */
    private function rulesFor($sessions): array
    {
        $workspaceIds = $sessions->pluck('workspace_id')->unique()->all();
        $courseIds = $sessions->pluck('course_id')->filter()->unique()->all();

        $rows = UnlockRule::query()
            ->withoutWorkspaceScope()
            ->whereIn('workspace_id', $workspaceIds)
            // The default and the specific in ONE query — the whole point of the
            // zero sentinel rather than a nullable column.
            ->whereIn('course_id', [...$courseIds, UnlockRule::DEFAULT_SCOPE])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->workspace_id][(int) $row->course_id] = $row;
        }

        return $out;
    }

    /**
     * @param  list<int>  $classSessionIds
     * @return array<int, true>
     */
    private function exemptSessionIds(User $student, array $classSessionIds): array
    {
        $rows = UnlockExemption::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->whereIn('class_session_id', $classSessionIds)
            ->pluck('class_session_id');

        $out = [];

        foreach ($rows as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * The published homework of each of these sessions, and what this student did with it.
     *
     * @param  list<int>  $sessionIds
     * @return array<int, array{submitted: bool, score: float|null, points: int}>
     */
    private function homeworkFor(User $student, array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        // ⚠️ PUBLISHED ONLY (FR-042). A teacher who started writing homework on
        // Tuesday and never finished must not thereby have locked their whole
        // class out of Wednesday — with no message naming a cause they can act on.
        $assignments = Assignment::query()
            ->withoutWorkspaceScope()
            ->published()
            ->whereIn('class_session_id', $sessionIds)
            ->get(['id', 'class_session_id', 'points']);

        if ($assignments->isEmpty()) {
            return [];
        }

        $submissions = Submission::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->get(['assignment_id', 'submitted_at', 'score'])
            ->keyBy('assignment_id');

        $out = [];

        foreach ($assignments as $assignment) {
            $submission = $submissions->get($assignment->id);

            $out[(int) $assignment->class_session_id] = [
                // ⚠️ `submitted_at`, NOT `state !== missed`. The `pending` state
                // exists for a row created by granting an extension — nothing has
                // been handed in, and reading the state as a proxy would call
                // that a hand-in.
                'submitted' => $submission?->submitted_at !== null,
                'score' => $submission?->score === null ? null : (float) $submission->score,
                'points' => (int) $assignment->points,
            ];
        }

        return $out;
    }
}
