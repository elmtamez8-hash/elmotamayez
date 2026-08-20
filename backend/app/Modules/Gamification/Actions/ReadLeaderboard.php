<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Gamification\Enums\LeaderboardPeriod;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\GamificationSettings;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\DisplayName;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One leaderboard, sliced to the reader's own band (FR-020 … FR-028).
 *
 * ⚠️ EVERY REFUSAL IN THIS CLASS IS 403, INCLUDING "NO SUCH THING". A distinct
 * 404 for an identifier that does not exist turns the endpoint into an oracle:
 * walk `course:{uuid}` until the answers differ and you have enumerated the
 * platform. The uuid was chosen to make that expensive; a different status code
 * would give it back.
 */
class ReadLeaderboard extends Action
{
    public function __construct(
        private readonly GamificationCalendar $calendar,
        private readonly GamificationSettings $settings,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /** @return array<string, mixed> */
    public function handle(User $reader, string $rawScope, LeaderboardPeriod $period): array
    {
        $parsed = LeaderboardScope::parse($rawScope);

        if ($parsed === null) {
            $this->refuse();
        }

        [$scope, $identifier] = $parsed;

        $scopeKey = $this->authorise($reader, $scope, $identifier);

        $periodKey = $period === LeaderboardPeriod::Week
            ? $this->calendar->weekKey()
            : $this->calendar->termKey();

        $mine = LeaderboardEntry::query()
            ->where('scope_key', $scopeKey)
            ->where('period_key', $periodKey)
            ->where('user_id', $reader->getKey())
            ->first();

        /*
        | The reader's own band decides which slice they see (FR-023 · SC-009).
        |
        | ⚠️ BAND FIRST, RANK WINDOW SECOND. Slicing by rank alone satisfies
        | "at most fifty" to the letter and puts a level-2 student having a good
        | week among level-40 grinders — the opposite of why the slice exists.
        | With no row of their own yet, they look at the entry band.
        */
        $band = $mine === null ? 0 : $mine->level_band;
        $window = $this->settings->leaderboardWindow();

        $rows = LeaderboardEntry::query()
            ->where('scope_key', $scopeKey)
            ->where('period_key', $periodKey)
            ->where('level_band', $band)
            ->orderBy('rank')
            // One query for every display name, instead of one per row.
            ->with('user:id,first_name,last_name')
            ->limit($window)
            ->get();

        return [
            // Echoed in the form it arrived, never the stored one: the client sent
            // a uuid and would not recognise the internal id it resolves to.
            'scope' => $rawScope,
            'period' => $periodKey,
            'level_band' => $band,
            'my_rank' => $mine?->rank,
            'my_points' => $mine?->points,
            'entries' => $rows->map(fn (LeaderboardEntry $entry): array => [
                'rank' => $entry->rank,
                /*
                | ⚠️ THE ABBREVIATED NAME, ALWAYS — on the restricted scopes too.
                | Three of the six cross workspaces, so a full surname there is a
                | minor identified to the whole platform; using the short form
                | everywhere means the rule cannot be got wrong by adding a scope.
                */
                'display_name' => DisplayName::forStudent($entry->user),
                'points' => $entry->points,
                'level' => $entry->level_band,
            ])->all(),
        ];
    }

    /**
     * Decide whether this reader may see this board, and resolve the stored key.
     *
     * @return string the `scope_key`
     */
    private function authorise(User $reader, LeaderboardScope $scope, string $identifier): string
    {
        if ($scope->isCrossWorkspace()) {
            /*
            | ⚠️ STUDENTS ONLY (Q9 · FR-020د · SC-023).
            |
            | A teacher holds no level band, so there is no natural bound on what
            | they would see — and the constitution forbids them learning anything
            | about a student with no active enrolment in their own workspace, EVEN
            | when the row is platform-owned. Opening these would hand every
            | teacher a weekly roster of their competitors' students: an
            | abbreviated name, a grade, a subject and proof of activity.
            */
            if ($reader->platform_role !== PlatformRole::Student) {
                $this->refuse();
            }

            return match ($scope) {
                LeaderboardScope::Platform => LeaderboardScope::Platform->keyFor(''),
                LeaderboardScope::Subject => $scope->keyFor((string) $this->idOf(Subject::query(), $identifier)),
                // The grade is stored by SLUG on `courses`, so it needs no lookup —
                // and the slug is the platform vocabulary either way.
                LeaderboardScope::Grade => $scope->keyFor($identifier),
                default => $this->refuse(),
            };
        }

        return match ($scope) {
            LeaderboardScope::Teacher => $scope->keyFor((string) $this->workspaceFor($reader, $identifier)),
            LeaderboardScope::Course => $scope->keyFor((string) $this->courseFor($reader, $identifier)),
            LeaderboardScope::Lesson => $scope->keyFor((string) $this->lessonFor($reader, $identifier)),
            default => $this->refuse(),
        };
    }

    private function workspaceFor(User $reader, string $uuid): int
    {
        $id = $this->idOf(Workspace::query(), $uuid);

        if (! $this->enrollments->hasActiveEnrollmentInWorkspace($reader, $id)) {
            $this->refuse();
        }

        return $id;
    }

    private function courseFor(User $reader, string $uuid): int
    {
        // withoutWorkspaceScope: the reader is a student and belongs to no
        // workspace, so the scope adds no condition anyway — but saying so
        // explicitly is what keeps this working when called with a context.
        $id = $this->idOf(Course::query()->withoutWorkspaceScope(), $uuid);

        if (! $this->enrollments->hasActiveEnrollment($reader, $id)) {
            $this->refuse();
        }

        return $id;
    }

    private function lessonFor(User $reader, string $uuid): int
    {
        $lesson = Lesson::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();

        if ($lesson === null || ! $this->enrollments->hasActiveEnrollment($reader, (int) $lesson->course_id)) {
            $this->refuse();
        }

        return (int) $lesson->getKey();
    }

    /** @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query */
    private function idOf($query, string $uuid): int
    {
        $id = $query->where('uuid', $uuid)->value('id');

        if ($id === null) {
            $this->refuse();
        }

        return (int) $id;
    }

    /**
     * @return never
     *
     * @throws HttpException
     */
    private function refuse(): string
    {
        abort(403, 'لا تملك صلاحية عرض هذا الترتيب.');
    }
}
