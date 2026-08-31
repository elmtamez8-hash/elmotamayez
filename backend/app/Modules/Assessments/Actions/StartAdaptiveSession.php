<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Data\AdaptiveStartData;
use App\Modules\Assessments\Enums\AdaptiveStatus;
use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Exceptions\AdaptiveConflictException;
use App\Modules\Assessments\Exceptions\FeatureDisabledException;
use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Support\AdaptiveLadder;
use App\Modules\Assessments\Support\AdaptiveSettings;
use App\Modules\Assessments\Support\PracticePaper;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Flags;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Open one adaptive practice session on one concept (spec 012 · FR-001).
 *
 * ⚠️ NOTHING HERE IS RESOLVED BY ROUTE-MODEL BINDING. `WorkspaceScope` adds no
 * condition when the context is null and it is null for every student, so an
 * implicit `{concept}` would resolve any teacher's concept in the product. Both
 * uuids are resolved inside this Action, after the entitlement check — the
 * `RedeemReward` precedent.
 */
class StartAdaptiveSession extends Action
{
    public const FLAG = 'adaptive_practice';

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly AdaptiveLadder $ladder,
        private readonly AdaptiveSettings $settings,
        private readonly PracticePaper $paper,
        private readonly Flags $flags,
    ) {}

    /**
     * @return array{session: AdaptiveSession, item: AttemptItem|null, resumed: bool}
     *
     * @throws FeatureDisabledException when this teacher has the feature switched off
     * @throws DomainException when the concept is out of reach or has nothing to ask
     */
    public function handle(User $student, AdaptiveStartData $data): array
    {
        $workspaceId = $this->teacherWorkspace($student, $data->teacherUuid);

        if ($workspaceId === null) {
            throw new DomainException('اختر مدرّساً تدرس عنده الآن.');
        }

        /*
        | ⚠️ THE SWITCH IS READ WITH THIS ID, NEVER FROM `WorkspaceContext`. That
        | is null for every student and `(int) null === 0` addresses the platform
        | default row — which ships OFF — so the feature would be refused to
        | everybody while the panel showed it enabled for the teacher.
        */
        if (! $this->flags->enabled(self::FLAG, $workspaceId)) {
            throw new FeatureDisabledException('التدريب التكيّفي غير مفعَّل عند هذا المدرّس.');
        }

        $conceptId = $this->conceptId($workspaceId, $data->conceptUuid);

        if ($conceptId === 0) {
            throw new DomainException('لا توجد هذه الفكرة عند هذا المدرّس.');
        }

        // The cheap path: a session they already have open. Checked before any
        // row is written, so the ordinary «I reloaded the page» costs one read.
        $running = $this->runningSession($student, $conceptId);

        if ($running !== null) {
            throw new AdaptiveConflictException('لديك جلسة جارية على هذه الفكرة.');
        }

        $ceiling = $this->ladder->ceilingFor($workspaceId, $student, $conceptId);

        if ($ceiling === null) {
            throw new DomainException('لا أسئلة متاحة لك في هذه الفكرة الآن.');
        }

        $start = $this->settings->startDifficulty();

        // A start above the ceiling is a level this concept does not have.
        if ($start->rank() > $ceiling->rank()) {
            $start = $ceiling;
        }

        /*
        | ⚠️ THE ATTEMPT IS WRITTEN BEFORE THE CLAIM, AND THE LOSER DELETES ITS
        | OWN ROWS. `AdaptiveLadder::next()` needs an attempt id to know what has
        | already been served, so the claim cannot come first — the same ordering
        | `IngestSessionRecordingJob` uses for exactly this reason, and it is safe
        | for the same reason: nothing has been delivered yet, so a loser has spent
        | two local inserts and no side effect at all.
        |
        | Wrapping the whole method in one transaction would be WORSE, not safer:
        | a concurrent start nested inside it becomes a savepoint, and the outer
        | rollback would discard the winner's session along with the loser's.
        */
        $attempt = $this->paper->write($workspaceId, $student, collect());

        $question = $this->ladder->next($workspaceId, $student, $conceptId, $start, (int) $attempt->getKey());

        if ($question === null) {
            $this->discard($attempt);

            throw new DomainException('لا أسئلة متاحة لك في هذه الفكرة الآن.');
        }

        $item = $this->paper->append($attempt, $question);

        $session = $this->claim($workspaceId, $student, $conceptId, $attempt, AdaptiveLadder::difficultyOf($question), $ceiling);

        if ($session === null) {
            $this->discard($attempt);

            throw new AdaptiveConflictException('لديك جلسة جارية على هذه الفكرة.');
        }

        return ['session' => $session, 'item' => $item, 'resumed' => false];
    }

    /**
     * The session they already have open on this concept, with its question.
     *
     * Called by the controller when {@see handle()} refuses with a conflict: the
     * refusal is «you already have one», and a client told that without being
     * given the session has a uuid it can do nothing with.
     *
     * @return array{session: AdaptiveSession, item: AttemptItem|null, resumed: true}|null
     */
    public function resume(User $student, AdaptiveStartData $data): ?array
    {
        $workspaceId = $this->teacherWorkspace($student, $data->teacherUuid);

        if ($workspaceId === null) {
            return null;
        }

        $session = $this->runningSession($student, $this->conceptId($workspaceId, $data->conceptUuid));

        return $session === null
            ? null
            : ['session' => $session, 'item' => $session->currentItem(), 'resumed' => true];
    }

    /**
     * Write the session row and its claim in one statement.
     *
     * ⚠️ `insertOrIgnore` PLUS AN EXPLICIT `uuid` AND TIMESTAMPS. A raw insert
     * boots no model, so `HasUuid` never fires — and on MySQL the resulting NOT
     * NULL violation is downgraded to a warning and `''` is stored, after which
     * every later session on the platform collides with that row on
     * `unique(uuid)` and is silently read as a duplicate.
     *
     * Zero rows means the claim was lost, which is the ordinary «two tabs» case
     * and not a failure: the caller reads back the winner.
     */
    private function claim(
        int $workspaceId,
        User $student,
        int $conceptId,
        Attempt $attempt,
        Difficulty $start,
        Difficulty $ceiling,
    ): ?AdaptiveSession {
        $now = now();

        $written = AdaptiveSession::query()->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'workspace_id' => $workspaceId,
            'student_user_id' => $student->getKey(),
            'concept_id' => $conceptId,
            'attempt_id' => $attempt->getKey(),
            'current_difficulty' => $start->value,
            'ceiling_difficulty' => $ceiling->value,
            'correct_streak' => 0,
            // The first question is already served by the time this runs.
            'served_count' => 1,
            'status' => AdaptiveStatus::Running->value,
            'running_key' => AdaptiveSession::runningKeyFor((int) $student->getKey(), $conceptId),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($written === 0) {
            return null;
        }

        return AdaptiveSession::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $attempt->getKey())
            ->firstOrFail();
    }

    private function runningSession(User $student, int $conceptId): ?AdaptiveSession
    {
        if ($conceptId === 0) {
            return null;
        }

        return AdaptiveSession::query()
            ->withoutWorkspaceScope()
            // The claim key IS the predicate — one spelling for the read and for
            // the unique index that enforces it.
            ->where('running_key', AdaptiveSession::runningKeyFor((int) $student->getKey(), $conceptId))
            ->first();
    }

    /**
     * Undo this call's own two inserts. Nothing has been delivered, so there is
     * nothing else to unwind.
     */
    private function discard(Attempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            AttemptItem::query()->withoutWorkspaceScope()->where('attempt_id', $attempt->getKey())->delete();
            Attempt::query()->withoutWorkspaceScope()->whereKey($attempt->getKey())->delete();
        });
    }

    /**
     * The teacher this session belongs to, or null to refuse.
     *
     * `MistakeController::practiceWorkspace()`'s ladder, spelled the same way:
     * context first for a reader who has one (a teacher trying their own bank),
     * active enrolments second for a real student — who has no context at all.
     * A named uuid that is not one of theirs refuses rather than falling through
     * to a different teacher's bank.
     */
    private function teacherWorkspace(User $student, string $teacherUuid): ?int
    {
        $context = app(WorkspaceContext::class)->id();

        $readable = $context !== null
            ? [$context]
            : $this->enrollments->activeWorkspaceIdsFor($student);

        if ($teacherUuid !== '') {
            $named = (int) Workspace::query()->withoutGlobalScopes()->where('uuid', $teacherUuid)->value('id');

            return in_array($named, $readable, true) ? $named : null;
        }

        return count($readable) === 1 ? $readable[0] : null;
    }

    /** Zero when the uuid names nothing in this bank — never the unfiltered one. */
    private function conceptId(int $workspaceId, string $uuid): int
    {
        return (int) Concept::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $uuid)
            ->value('id');
    }
}
