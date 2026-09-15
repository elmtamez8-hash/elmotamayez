<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Http\Resources\CohortResource;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\CohortScheduleDirectory;
use App\Shared\Support\CountedNoun;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Learning's answer to "which group?".
 *
 * ⚠️ EVERY QUERY HERE RUNS WITHOUT THE WORKSPACE SCOPE, and that is the same
 * decision {@see EloquentEnrollmentDirectory} wrote down: a student is a member
 * of no workspace, so `WorkspaceContext::id()` is null, `WorkspaceScope` adds no
 * condition anyway — and the moment a request DOES carry a context (a teacher
 * reading their own screen) a scoped query here would answer about the reader's
 * workspace rather than about the course being asked about. The guard is the
 * student's own id and the course's own id, both of which are stricter than a
 * workspace filter.
 */
class EloquentCohortDirectory implements CohortDirectory
{
    public function __construct(
        private readonly CohortScheduleDirectory $schedule,
    ) {}

    public function hasOpenMembership(User $user, int $courseId): bool
    {
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->whereNull('closed_at')
            ->exists();
    }

    public function openMembershipCohortId(User $user, int $courseId): ?int
    {
        $id = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->whereNull('closed_at')
            ->value('cohort_id');

        return $id === null ? null : (int) $id;
    }

    public function everMemberCohortIdsFor(User $user): array
    {
        // No `closed_at` filter, deliberately — see the contract.
        return array_values(CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->distinct()
            ->pluck('cohort_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    public function joinableCohortsExist(int $courseId): bool
    {
        return Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            /*
            | ⚠️ `->group()`, OR THE SAFETY VALVE OPENS ON A ROOM NOBODY MAY
            | ENTER. An individual cohort is born `closed` with `capacity: 1`
            | and no membership row, so `members_count` is 0 — and nothing stops
            | a teacher setting its status to `open` from the panel, after which
            | it satisfies `joinable()`. This predicate is what decides whether
            | the curriculum gate stays shut (FR-028أ/ب), so counting one would
            | lock a paying student out of content on the strength of a private
            | 1:1 group they can never join.
            */
            ->group()
            ->joinable()
            ->exists();
    }

    public function assignableCohortsExist(int $courseId): bool
    {
        // The bulk form is the implementation; one course is a list of one. Two
        // spellings of one predicate is the defect FR-030 exists over.
        return $this->coursesWithAssignableCohorts([$courseId]) !== [];
    }

    public function courseIsFull(int $courseId): bool
    {
        // Both halves, in the order that costs least: a course with no groups at
        // all is the common case and answers on the first query.
        return $this->coursesWithCohorts([$courseId]) !== []
            && $this->coursesWithAssignableCohorts([$courseId]) === [];
    }

    /** @return list<int> */
    public function coursesWithAssignableCohorts(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return array_values(Cohort::query()
            ->withoutWorkspaceScope()
            ->whereIn('course_id', $courseIds)
            /*
            | ⚠️ `->group()`, FOR THE REASON ITS TWO SIBLINGS ABOVE CARRY — and
            | here it is the sharper one. `assignable()` does not look at the
            | status at all, so an EMPTY private 1:1 room satisfies it outright:
            | without this filter the officer's picker would offer one named
            | student's private hour as a destination for a second student.
            */
            ->group()
            ->assignable()
            ->distinct()
            ->pluck('course_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    public function wasEverMember(User $user, int $cohortId): bool
    {
        // No `closed_at` condition at all — "ever" is the question (FR-046), and
        // adding one here is how the departed member loses the thread they are
        // entitled to keep reading.
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('cohort_id', $cohortId)
            ->exists();
    }

    public function isCurrentMember(User $user, int $cohortId): bool
    {
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('cohort_id', $cohortId)
            ->whereNull('closed_at')
            ->exists();
    }

    /** @return list<int> */
    public function activeMemberIdsFor(int $cohortId): array
    {
        $ids = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('cohort_id', $cohortId)
            ->whereNull('closed_at')
            ->pluck('student_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    /** @return list<int> */
    public function openMembershipCohortIdsFor(User $user): array
    {
        $ids = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->whereNull('closed_at')
            ->pluck('cohort_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    /**
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function coursesWithCohorts(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $ids = Cohort::query()
            ->withoutWorkspaceScope()
            ->whereIn('course_id', $courseIds)
            /*
            | ⚠️ `->group()`, THE SIBLING OF `joinableCohortsExist()`'s — AND ITS
            | ABSENCE HERE WAS LIVE. This answers «does this course run in
            | groups», which is what `CohortSessionVisibility` uses to decide
            | whether an unassigned session is hidden. A private 1:1 cohort is
            | not a run of the course: it is one named student's own room, and
            | `DecidePrivateSessionRequest` creates one every time a teacher
            | grants a private hour. So one granted request made the course
            | «grouped», and every unassigned session on it vanished from every
            | student's timetable at that instant — no error, no message, and the
            | teacher's own «حصص محجوبة» panel offering a group to assign to that
            | the student could never be in.
            */
            ->group()
            ->distinct()
            ->pluck('course_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    public function resolveCohortId(string $uuid, int $courseId): ?int
    {
        $id = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->where('course_id', $courseId)
            /*
            | ⚠️ `->group()` AS WELL AS THE COURSE. The course in the question is
            | what stops a caller naming any cohort uuid on the platform; this is
            | what stops them naming a private 1:1 cohort. Those are born `closed`
            | with `capacity: 1`, but a teacher can open one from the panel — and
            | this method is now reached from a STUDENT-supplied uuid on the
            | subscription path (027), which makes an opened individual cohort a
            | way into another named student's room and its thread.
            */
            ->group()
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{id: int, course_id: int, workspace_id: int, name: string, course_uuid: string, is_joinable: bool}|null */
    public function describeGroupCohort(string $uuid): ?array
    {
        $cohort = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            // See the contract: no course narrows this, so the group filter is
            // the only thing standing between a stranger's uuid and a private
            // 1:1 room. The caller proves the rest from what comes back.
            ->group()
            ->first(['id', 'course_id', 'workspace_id', 'name', 'status', 'capacity', 'members_count']);

        if ($cohort === null) {
            return null;
        }

        /*
        | ⚠️ THE COURSE UUID IS READ WITH ITS OWN BYPASS, NOT THROUGH `->with()`.
        | An eager load runs a SECOND query on which `Course`'s own
        | `BelongsToWorkspace` scope applies afresh — the bypass above frees only
        | the outer read. This method is asked about ANOTHER teacher's group by
        | design (that is the case it exists to refuse), and from the panel the
        | reader's own workspace is a real number: the relation came back null and
        | the refusal became a 500. That is the fifth layer of the 024 defect,
        | reached from a new door, and a test with one workspace cannot see it.
        */
        $course = Course::query()
            ->withoutWorkspaceScope()
            ->whereKey($cohort->course_id)
            ->first(['uuid', 'status']);

        if ($course === null) {
            return null;
        }

        return [
            'id' => (int) $cohort->getKey(),
            'course_id' => (int) $cohort->course_id,
            'workspace_id' => (int) $cohort->workspace_id,
            'name' => (string) $cohort->name,
            'course_uuid' => (string) $course->uuid,
            /*
            | ⚠️ THE COURSE'S STATUS TRAVELS TOO (027 · FR-026). A subscription
            | opens access by enrolling in every PUBLISHED course the plan covers,
            | so a group whose course was unpublished between the order and the
            | approval produces no enrolment — and the membership step then
            | refuses with «لست مسجّلاً», after the money has committed and with
            | nothing on the officer's screen. Answering it here lets the approval
            | be refused while it can still be refused.
            */
            'course_status' => (string) $course->status,
            'is_joinable' => $cohort->isJoinable(),
        ];
    }

    public function claimSeat(int $cohortId): bool
    {
        // ⚠️ مُفوَّضةٌ إلى الكاتب، لا مُعادةَ الإملاءِ هنا — هو مالكُ نمطِ المقعدِ
        // في هذه الوحدة، وإملاءانِ للجملةِ نفسِها يفترقانِ عندَ أوّلِ تعديلٍ
        // لشرطِ السعة.
        return CohortMembershipWriter::claimSeat($cohortId);
    }

    public function isAssignable(int $cohortId): bool
    {
        $cohort = Cohort::query()->withoutWorkspaceScope()->whereKey($cohortId)->first();

        // ⚠️ مُفوَّضةٌ إلى النموذجِ كتوأمِها أدناه، و`isIndividual()` معها: غرفةُ
        // طالبٍ بعينِه تُرضي `isAssignable()` وحدَها لأنّها لا تنظرُ إلى الحالة.
        return $cohort !== null && ! $cohort->isIndividual() && $cohort->isAssignable();
    }

    /** @return array<string, string> */
    public function assignableOptionsFor(int $courseId): array
    {
        $cohorts = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            // ⚠️ `assignable()` لا `joinable()` (FR-030)، و`group()` تُخرِجُ الغرفةَ
            // الخاصّة — وهي `closed` بسعةِ واحد، فتُرضي `assignable()` وحدَها.
            ->group()
            ->assignable()
            ->orderBy('name')
            ->get();

        /*
        | ⚠️ **والموعدُ يُقرَأُ جُملةً واحدةً لكلِّ المجموعات، لا واحدةً واحدة.**
        | هذه الخياراتُ تُبنى داخلَ `->options()` في Filament، فسؤالٌ لكلِّ صفٍّ
        | هو N+1 بالتكوين — على شاشةٍ يفتحُها الموظَّفُ لكلِّ طلب.
        | `schedulePreviewFor()` جمليٌّ بالتعريف، ويُرجِعُ **قائمةً فارغةً** لا
        | مفتاحاً غائباً لمجموعةٍ بلا حصص (عقدُه يقولُ ذلك صراحةً).
        */
        $schedules = $this->schedule->schedulePreviewFor(
            // `array_values(...)` — الإملاءُ نفسُه الذي يستعملُه كلُّ منادٍ آخرَ
            // لهذا العقد: `map()` يُبقي مفاتيحَ المجموعة، والعقدُ يطلبُ `list<int>`.
            array_values($cohorts->map(static fn (Cohort $cohort): int => (int) $cohort->getKey())->all()),
        );

        return $cohorts
            ->mapWithKeys(static function (Cohort $cohort) use ($schedules): array {
                /*
                | ⛔ **والموعدُ حقيقةُ القرارِ الأولى، لا زينة.** الموظَّفُ يُسنِدُ
                | طالباً إلى مجموعة، والسؤالُ الذي يُقرَّرُ عليه «متى تجتمع» —
                | والاسمُ وحدَه يحملُه بالعُرفِ («الأحد ٦م») لا بالبيانات، فمجموعةٌ
                | سُمِّيَت «المجموعة الثانية» لا تقولُ شيئاً.
                |
                | ⚠️ **و«لم تُجدول» تُكتَبُ ولا تُترَك**: مجموعةٌ بلا حصصٍ قرارٌ
                | مختلفٌ عن مجموعةٍ لها موعد، وفراغٌ في مكانِ الموعدِ يُقرَأُ خطأً
                | في الشاشةِ لا حقيقةً عن المجموعة.
                */
                $slots = $schedules[(int) $cohort->getKey()] ?? [];

                $parts = [
                    $cohort->name,
                    $slots === [] ? 'لم تُجدول حصص بعد' : implode(' · ', $slots),
                ];

                /*
                | ⚠️ **الاسمُ المعدودُ يُوافَقُ، ولا يُكتَبُ «مقعداً» لكلِّ عدد.**
                | «٣ مقعداً» خطأٌ في العربيّة، و«مقعد واحد» لا تُسبَقُ برقم.
                | و`CountedNoun` يسألُ CLDR كما تسألُه الواجهةُ في `counted()` —
                | فالشاشتانِ تقولانِ الشيءَ نفسَه، ولا سُلَّمَ مكتوباً باليدِ
                | يفترقُ فوقَ المئة.
                |
                | و`null` سعةٌ بلا حدّ، فلا رقمَ يُقال.
                */
                if ($cohort->seatsLeft() !== null) {
                    $parts[] = CountedNoun::of($cohort->seatsLeft(), [
                        'one' => 'مقعد واحد متبقٍّ',
                        'two' => 'مقعدان متبقّيان',
                        'few' => 'مقاعد متبقّية',
                        'many' => 'مقعداً متبقّياً',
                        'other' => 'مقعد متبقٍّ',
                    ]);
                }

                return [(string) $cohort->uuid => implode(' — ', $parts)];
            })
            ->all();
    }

    public function isJoinable(int $cohortId): bool
    {
        $cohort = Cohort::query()
            ->withoutWorkspaceScope()
            ->whereKey($cohortId)
            ->first(['id', 'status', 'capacity', 'members_count']);

        /*
        | ⚠️ DELEGATED TO THE MODEL, NEVER RE-SPELLED. `Cohort::isJoinable()` is
        | `status === OPEN && ! isFull()`, and it is what `CohortResource` and the
        | picker already read. A third spelling of one question is how the card
        | says yes and the door says no — which is the defect FR-002 exists over.
        */
        return $cohort !== null && $cohort->isJoinable();
    }

    public function publicCohortsFor(int $courseId): array
    {
        $cohorts = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            // Ownership, not status — see the contract. An archived group is
            // dropped by name because FR-010 says so; a `closed` one is
            // published WITH its status, because «مغلقة» is information a
            // visitor deciding between groups needs.
            ->group()
            ->where('status', '!=', Cohort::ARCHIVED)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'description', 'status', 'capacity', 'members_count']);

        $out = [];

        foreach ($cohorts as $cohort) {
            $out[] = [
                'id' => (int) $cohort->getKey(),
                'uuid' => (string) $cohort->uuid,
                'name' => (string) $cohort->name,
                'description' => $cohort->description,
                /*
                | The three states of FR-011, and CLOSED OUTRANKS FULL.
                | A closed group cannot be joined whatever its seat count, so
                | «ممتلئة» there would invite the visitor to wait for a place
                | that will never open. `isFull()` is derived from the counter
                | against the ceiling and never stored, and a group with no
                | declared ceiling is never full.
                */
                'status' => match (true) {
                    $cohort->status === Cohort::CLOSED => 'closed',
                    $cohort->isFull() => 'full',
                    default => 'open',
                },
                'seats_left' => $cohort->seatsLeft(),
                /*
                | ⚠️ THE SERVER'S ANSWER, NOT A CONDITION THE BROWSER REBUILDS
                | (FR-002). Derived here from columns already selected, never by
                | asking `isJoinable(int)` once per row — this method feeds a
                | Resource, and a Resource runs once per row.
                */
                'is_joinable' => $cohort->isJoinable(),
            ];
        }

        return $out;
    }

    public function teacherCohortsFor(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $cohorts = Cohort::query()
            ->withoutWorkspaceScope()
            ->whereIn('course_id', $courseIds)
            /*
            | ⚠️ `->group()`: a private 1:1 cohort is one student's own room —
            | born `closed` with `capacity: 1` and the student's id on it. Listed
            | on a course card it would put a named student's private
            | arrangement in a grid the whole workspace reads, and would drown
            | the real groups the teacher is looking for.
            */
            ->group()
            // The groups tab's own ordering, character for character.
            ->orderBy('status')
            ->orderBy('name')
            ->get();

        // ONE call for every group of every course on the page — the whole
        // reason this method takes a list.
        $preview = $this->schedule->schedulePreviewFor(
            array_values($cohorts->map(fn (Cohort $cohort): int => (int) $cohort->getKey())->all()),
        );

        $out = [];

        foreach ($cohorts as $cohort) {
            $out[(int) $cohort->course_id][] = CohortResource::make(
                $cohort,
                $preview[(int) $cohort->getKey()] ?? [],
            )->resolve();
        }

        return $out;
    }

    public function ensureIndividualCohort(int $courseId, int $workspaceId, User $student, ?User $creator): int
    {
        $existing = $this->findIndividualCohort($courseId, (int) $student->getKey());

        if ($existing !== null) {
            return $existing;
        }

        $cohort = new Cohort;

        /*
        | ⚠️ `forceFill`, BECAUSE `status` AND `members_count` ARE NOT `$fillable`.
        | A `create()` array carrying `status` has it discarded in silence and the
        | group is born `open` — which puts one student's private group into the
        | course's joinable list and into `joinableCohortsExist()`, where FR-028أ
        | would then gate the whole curriculum behind a group nobody else may
        | ever enter.
        |
        | ⚠️ AND NO MEMBERSHIP IS OPENED HERE OR ANYWHERE. `cohort_memberships`
        | carries `unique(student_user_id, course_id, closed_slot)` — ONE open
        | membership per (student, course) — so opening one would CLOSE the
        | student's Saturday group and take away the weekly class they paid for,
        | in exchange for one private hour. The private session reaches its
        | student through their own booking, which is where a lesson they hold a
        | seat in belongs.
        */
        $cohort->forceFill([
            'workspace_id' => $workspaceId,
            'course_id' => $courseId,
            // `unique(course_id, name)` is on the NAME, so the id is part of it:
            // two students called Sara in one course would otherwise make the
            // second acceptance fail with a message about a group name.
            'name' => mb_substr('حصص خاصة — '.$student->name, 0, 100).' #'.$student->getKey(),
            'capacity' => 1,
            'status' => Cohort::CLOSED,
            'individual_for_user_id' => $student->getKey(),
            'created_by' => $creator?->getKey(),
        ]);

        try {
            $cohort->save();
        } catch (UniqueConstraintViolationException) {
            // The other acceptance won the index. Hand back its group rather
            // than an error: one group is exactly the outcome asked for.
            $winner = $this->findIndividualCohort($courseId, (int) $student->getKey());

            if ($winner === null) {
                throw new RuntimeException('تعذّر تجهيز مجموعة الحصص الخاصة.');
            }

            return $winner;
        }

        return (int) $cohort->getKey();
    }

    /**
     * @param  list<int>  $cohortIds
     * @return array<int, string>
     */
    public function namesFor(array $cohortIds): array
    {
        if ($cohortIds === []) {
            return [];
        }

        /*
        | ⚠️ `withoutWorkspaceScope()`, AND THE IDS ARE THE GUARD.
        |
        | The caller is a session row the reader is already entitled to see, so
        | the id itself has passed every door there is. Leaving the scope on
        | would answer differently for the two readers of the same calendar: a
        | teacher resolves a workspace and would get the name, while a STUDENT is
        | a member of no workspace at all — `WorkspaceContext::id()` is null for
        | them, the scope adds no condition, and they would get it too. One
        | answer for both, spelled out, beats one that is only accidentally the
        | same.
        */
        return Cohort::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', array_values(array_unique($cohortIds)))
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    /**
     * @param  list<int>  $cohortIds
     * @return array<int, string>
     */
    public function uuidsFor(array $cohortIds): array
    {
        if ($cohortIds === []) {
            return [];
        }

        // `withoutWorkspaceScope()` for the reason {@see namesFor()} gives above
        // it: the ids are the guard, and a scoped read answers differently for a
        // teacher and for a student of the same course.
        return Cohort::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', array_values(array_unique($cohortIds)))
            ->pluck('uuid', 'id')
            ->mapWithKeys(fn (string $uuid, int|string $id): array => [(int) $id => $uuid])
            ->all();
    }

    private function findIndividualCohort(int $courseId, int $studentUserId): ?int
    {
        $id = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->where('individual_for_user_id', $studentUserId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
