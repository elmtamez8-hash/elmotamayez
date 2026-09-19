<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * "Which group is this student in, and is there one they could join?"
 *
 * Owned and implemented by `Learning`; asked by `LiveSessions` (which sessions
 * may this student see), by `Community` (who reaches the group's thread) and by
 * the curriculum gate. Same shape as {@see EnrollmentDirectory}: a question that
 * needs an answer before the next line runs, not an event announcing something
 * happened.
 *
 * ⚠️ EVERY READ THAT IS ASKED ABOUT A LIST IS BULK BY SIGNATURE. A Resource runs
 * once per row, so a single-row read inside one is an N+1 by construction — the
 * `ClassSessionResource` defect arriving through a new door.
 *
 * ⚠️ {@see joinableCohortsExist()} IS THE MOST IMPORTANT SIGNATURE HERE. Without
 * it the mandatory-membership gate becomes a permanent lock on paid content the
 * day every group is full, closed or archived — a condition no action of the
 * student's can satisfy, which is the family of the worst defect this repository
 * records.
 *
 * ⚠️ AND THERE IS DELIBERATELY NO `cohortIdsForSessions()`. The design sketched
 * one, and `class_sessions.cohort_id` is LiveSessions' own column on LiveSessions'
 * own table: an implementation here would be Learning reading another module's
 * schema to hand back what that module already holds. What LiveSessions cannot
 * answer for itself is which cohorts the READER belongs to, and which courses
 * have any cohorts at all — those two are below, and both are bulk.
 */
interface CohortDirectory
{
    /**
     * Whether this student currently belongs to a group of this course.
     *
     * The content gate (FR-028أ) — asked on every curriculum read, so it is one
     * indexed existence query and nothing more.
     */
    public function hasOpenMembership(User $user, int $courseId): bool;

    /** The group they are in right now, or `null`. */
    public function openMembershipCohortId(User $user, int $courseId): ?int;

    /**
     * Whether ANY group of this course could be joined at this instant — open,
     * and not full.
     *
     * ⚠️ THE SAFETY VALVE (FR-028ب). A `false` here does not close a door; it
     * opens the whole curriculum, because the condition has become one no
     * student action can satisfy.
     */
    public function joinableCohortsExist(int $courseId): bool;

    /**
     * Whether ANY group of this course could be ASSIGNED INTO at this instant —
     * open **or closed**, not full, not archived (٠٣٤ · FR-030).
     *
     * ⚠️ THE SECOND QUESTION, AND ASKING IT BY THE NAME ABOVE IS FORBIDDEN. A
     * `closed` group that is not full is a legitimate destination for
     * administration and an illegal one for a student, so the two answers differ
     * for a real and ordinary row — and {@see joinableCohortsExist()} additionally
     * answers `false` for a course with NO GROUPS AT ALL, by the identical value.
     * Four readers were asking one name for two questions.
     *
     * ⚠️ AND «no assignable group» IS NOT «no groups». They read the same here;
     * {@see coursesWithCohorts()} is what tells them apart, and a sale guard
     * written with one condition refuses every recorded course on the platform.
     */
    public function assignableCohortsExist(int $courseId): bool;

    /**
     * The bulk twin of {@see assignableCohortsExist()} — which of these courses
     * have one.
     *
     * ⚠️ BULK BY SIGNATURE, LIKE EVERY LIST-SHAPED READ HERE. The «مكتمل» badge
     * on a marketplace card is asked once per row, so the single-id form inside a
     * Resource is the `ClassSessionResource` N+1 arriving through a new door.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function coursesWithAssignableCohorts(array $courseIds): array;

    /**
     * Whether this course is FULL — it runs in groups, and not one of them could
     * be assigned into (٠٣٤ · FR-023).
     *
     * ⚠️ **TWO CONDITIONS, AND A GUARD WRITTEN WITH ONE REFUSES EVERY RECORDED
     * COURSE ON THE PLATFORM.** {@see assignableCohortsExist()} answers `false`
     * for a course whose every group is full AND for a course with no groups at
     * all, by the identical value — so «not assignable ⇒ refuse the sale» is
     * FR-025 inverted onto the money path by a one-line condition that looks
     * right. The pair lives here rather than at each caller because it is asked
     * on the purchase door, on the free-enrolment door, on the waitlist door and
     * on the public card — and four spellings of one predicate is the defect
     * FR-030 already exists over.
     */
    public function courseIsFull(int $courseId): bool;

    /**
     * Whether this person was a member of this group AT ANY POINT.
     *
     * Reading the old group's thread survives the transfer (FR-046); writing to
     * it does not. Two different questions, which is why they are two methods
     * and not one row read twice.
     */
    public function wasEverMember(User $user, int $cohortId): bool;

    /**
     * Whether this person's membership of this group is open RIGHT NOW.
     *
     * ⚠️ THE SECOND OF THE PAIR THE DOCBLOCK ABOVE PROMISES, and it is asked of
     * the COHORT rather than of the course. `openMembershipCohortId()` answers
     * the same fact but needs a course id, and the thread's row does not carry
     * one — deriving it would be a join to fetch back something the cohort id
     * already settles.
     */
    public function isCurrentMember(User $user, int $cohortId): bool;

    /**
     * Every cohort this student has EVER belonged to, closed ones included.
     *
     * ⚠️ THE BULK TWIN OF {@see wasEverMember()}, and it exists for the reason
     * that method's own docblock gives: a student moved between groups keeps the
     * hours they sat in. Asked one cohort at a time inside a loop it is the N+1
     * the curriculum read is budget-tested against.
     *
     * @return list<int>
     */
    public function everMemberCohortIdsFor(User $user): array;

    /**
     * Everyone whose membership of this group is open — the roster and the
     * announcement fan-out.
     *
     * @return list<int>
     */
    public function activeMemberIdsFor(int $cohortId): array;

    /**
     * Every group this student currently belongs to, across every course.
     *
     * ⚠️ BULK BECAUSE THE CALLER IS A QUERY, NOT A ROW. The session discovery
     * list is built before any row is known, so it cannot ask course by course.
     *
     * @return list<int>
     */
    public function openMembershipCohortIdsFor(User $user): array;

    /**
     * Which of these courses have at least one group (of any status).
     *
     * ⚠️ "HAS GROUPS" IS NOT "HAS JOINABLE GROUPS", and the difference is the
     * whole of FR-036: a course with no group at all behaves exactly as it did
     * before this spec — its sessions are the course's, not a group's, and
     * nothing about it is gated. An archived group still means the course is one
     * that runs in groups.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function coursesWithCohorts(array $courseIds): array;

    /**
     * The internal id of this group, IF it belongs to this course.
     *
     * ⚠️ THE COURSE IS PART OF THE QUESTION, NOT A COURTESY. Without it a
     * teacher assigning their own sessions could name any group uuid on the
     * platform and file their timetable under somebody else's run. Answering
     * `null` for a uuid that exists elsewhere is the same answer as for one that
     * does not exist at all — a distinct reply would be an oracle for which
     * uuids are real.
     */
    public function resolveCohortId(string $uuid, int $courseId): ?int;

    /**
     * Whether this group is open AND has a place — **structurally, with no
     * question about price** (spec 027 · FR-002 · FR-026 · ٠٣٦ · FR-018).
     *
     * ⛔ THE NAME CARRIES «STRUCTURALLY» BECAUSE IT IS A SECOND QUESTION, NOT A
     * WIDER SPELLING OF THE PICKER'S — exactly as {@see isAssignable()} is. ٠٣٦
     * added a price condition to what a STUDENT may join, and its one caller here
     * is an approval of an order that has already been paid for: re-asking the
     * price there would repossess a place over a plan the teacher switched off
     * while the transfer was clearing.
     *
     * ⚠️ ASKED TWICE ON PURPOSE, AT TWO MOMENTS. The subscription order is
     * created against a joinable group, and days can pass on a manual transfer
     * before an officer approves it — so it is asked again in `ApproveOrder`,
     * BEFORE the conditional claim, and a group that filled in between refuses
     * the approval rather than putting a student in a room with no chair
     * (FR-026). Asking it once, at either end, is one of the two halves missing.
     *
     * ⚠️ AND IT IS THE MODEL'S OWN PREDICATE, NOT A THIRD SPELLING. The card,
     * the picker and this all read the model's own predicate; two spellings put one
     * answer on the screen and another at the door.
     */
    public function isStructurallyJoinable(int $cohortId): bool;

    /**
     * Take one seat in this group, atomically. `false` means it just filled.
     *
     * ⚠️ **A READ IS NOT A CLAIM, AND FR-024أ SAYS SO IN AS MANY WORDS.** Two
     * officers approving two orders against the last seat both pass
     * {@see isStructurallyJoinable()} — it is a question, and the answer is stale the instant
     * it is given. This is one conditional UPDATE that is the check and the claim
     * together, so exactly one of them wins and the loser's approval is refused
     * **before any money is taken**, which is what makes «zero refunds caused by
     * a full group» (SC-009) a claim anybody can verify.
     *
     * ⚠️ AND THE CALLER OWNS THE TRANSACTION. The increment is rolled back with
     * whatever transaction it was issued inside, so a refusal further down gives
     * the seat back with no compensating write. A caller that claims outside a
     * transaction and then throws leaks a place.
     *
     * ⚠️ AND WHOEVER CLAIMS HERE MUST SAY SO TO THE WRITER. `CohortMembershipWriter::open()`
     * claims its own seat; reaching it afterwards without the flag increments
     * twice, leaking a place on every approval — and the second increment can
     * REFUSE with «full» after the money has already been taken.
     */
    public function claimSeat(int $cohortId): bool;

    /**
     * Whether ADMINISTRATION could put somebody into THIS group at this instant.
     *
     * ⚠️ The per-cohort twin of {@see assignableCohortsExist()}, and the door's
     * half of the pair {@see isStructurallyJoinable()} already has. The picker offers what
     * this answers and the write asks it again: two spellings put one answer on
     * the screen and another at the door, which is the defect FR-030 is about.
     */
    public function isAssignable(int $cohortId): bool;

    /**
     * The groups an officer may assign into on this course, ready for a picker:
     * `uuid => label`, ordered by name.
     *
     * ⚠️ **ONE STATEMENT AND ONE SPELLING, AND BOTH HALVES MATTER.** Two screens
     * ask this question — the assignment page and the approve button — and both
     * first wrote it as their own query; one of them looped {@see isAssignable()}
     * per row, which is N queries on a click AND a second spelling of the
     * predicate. The next person to change what «assignable» means would have
     * changed one of them.
     *
     * ⚠️ AND THE KEY IS THE PUBLIC UUID, never the autoincrement id: these values
     * travel through a form and into `ApproveOrder`, and `HasUuid` is the rule
     * that an id never leaves the server.
     *
     * @return array<string, string>
     */
    public function assignableOptionsFor(int $courseId): array;

    /**
     * Everything a buyer's chosen group has to prove, in one read (spec 027).
     *
     * ⚠️ IT TAKES NO COURSE, AND THAT IS WHY IT EXISTS BESIDE `resolveCohortId()`.
     * A plan whose coverage is «everything this teacher publishes» stamps no
     * course on the order at all — `coverageCourseId()` answers null for it — so
     * the caller has no course id to constrain the lookup with, and passing `0`
     * or null there would resolve ANY cohort uuid on the platform, on the one
     * path where `BelongsToWorkspace` protects nothing (a student is a member of
     * no workspace). The caller proves coverage instead, from the workspace and
     * course this returns.
     *
     * ⚠️ FILTERED TO GROUP COHORTS. A private 1:1 cohort is born `closed` with
     * `capacity: 1` and no membership row, and nothing stops a teacher opening
     * one from the panel — after which a stranger holding its uuid would
     * subscribe into another named student's room and its thread.
     *
     * @return array{id: int, uuid: string, course_id: int, workspace_id: int, name: string, course_uuid: string, course_status: string, is_joinable: bool}|null
     */
    public function describeGroupCohort(string $uuid): ?array;

    /*
    | Spec 023 · FR-010 — the groups of one course, as the PUBLIC may read them.
    |
    | ⚠️ FILTERED ON `individual_for_user_id IS NULL`, NEVER ON THE STATUS.
    | A private group is created `closed`, so filtering by status hides it today
    | for a reason that has nothing to do with whose it is — and the first day
    | one is opened for any reason at all, its owner's name is on the
    | marketplace. Ownership is the predicate; the status is a separate fact that
    | is also published.
    |
    | ⚠️ AND IT ANSWERS `seats_left`, NEVER `members_count` (FR-014). The
    | subtraction happens here so the two halves of it never both reach a
    | browser; `null` means no ceiling was declared, which is not a number and is
    | not zero.
    */
    /**
     * @return list<array{uuid: string, name: string, description: string|null, status: string, seats_left: int|null, is_joinable: bool, id: int}>
     */
    public function publicCohortsFor(int $courseId): array;

    /**
     * The groups of this course a STUDENT may be offered, ready for the picker.
     *
     * ⛔ THE GATE IS INSIDE THIS METHOD, NOT AT THE CALLER (٠٣٦ · FR-003 · T042).
     * A group no live price reaches is **absent**, not flagged: a card offering
     * the one action that cannot succeed is worse than no card. The filter lives
     * here for the same reason `assignableOptionsFor()` exists — two screens
     * asking one question as their own query is how one answer reaches the
     * screen and another reaches the door.
     *
     * ⚠️ AND A MEMBER STILL SEES THEIR OWN GROUP. Their membership is read by a
     * separate query that does not pass through here at all, so a group that has
     * dropped out of the offer stays visible to the people already in it — which
     * is what makes «لماذا لا أرى مجموعتي؟» answerable after this gate, and is
     * the promise that replaced the old «keep closed groups in the list» comment.
     *
     * ⚠️ Archived groups are out and `closed` ones are in: a closed group is a
     * run the reader can see is happening and cannot join, and hiding it makes
     * the same question unanswerable for a student whose classmates are in it.
     *
     * @return list<array<string, mixed>>
     */
    public function pickerCohortsFor(int $courseId): array;

    /**
     * Every group of each of these courses, as the TEACHER's list reads them —
     * name, seats, status and the times they meet.
     *
     * ⚠️ BULK BY SIGNATURE, LIKE EVERYTHING ELSE HERE. The caller is the course
     * list, which stamps the answer onto each row from outside; a per-row read
     * inside `CourseResource` is the `ClassSessionResource` N+1 arriving through
     * yet another door — and this one would drag `schedulePreviewFor()` in with
     * it, one query per group per course.
     *
     * ⚠️ AND IT IS THE SAME QUERY AND THE SAME PAYLOAD AS THE GROUPS TAB
     * (`ManageCohortController::index`), archived groups included. The card and
     * the tab answer one question about one course, and two spellings of it put
     * one count on the card and another on the screen it links to.
     *
     * @param  list<int>  $courseIds
     * @return array<int, list<array<string, mixed>>> keyed by course id; a
     *                                                course with no groups is
     *                                                simply absent
     */
    public function teacherCohortsFor(array $courseIds): array;

    /**
     * The student's own one-seat group in this course, created on first use
     * (023 · FR-019ج · FR-019د).
     *
     * ⚠️ IDEMPOTENT BY UNIQUE INDEX, never by a read followed by a write. Two
     * acceptances for one student arriving together both read «no group» and
     * both create one; `unique(course_id, individual_for_user_id)` is what makes
     * the loser lose, and the loser is answered with the winner's id rather than
     * an error — a second group is not something the caller can do anything
     * about.
     *
     * Here rather than in the caller because `Cohort` is Learning's model and
     * LiveSessions may not reach for it — `CohortSessionVisibility` says so in as
     * many words, and one module borrowing another's model once is how the
     * boundary stops being one. Named in prose rather than with `{@see}`: an
     * `App\Shared` contract that imports a module is the coupling inverted.
     *
     * @return int the cohort's primary key
     */
    public function ensureIndividualCohort(int $courseId, int $workspaceId, User $student, ?User $creator): int;

    /**
     * The names of these groups, by id — one query for a whole calendar.
     *
     * ⚠️ BULK, AND THAT IS THE ONLY REASON IT EXISTS. A Resource runs once per
     * row, so a name asked inside one is an N+1 by construction — a month of
     * sessions is fifty extra queries on the teacher's calendar and on the
     * dashboard that reads the same list. `teacherCohortsFor()` above answers a
     * different question (every group of a course, with its schedule); this one
     * answers «what are these particular groups called», which is what a session
     * row needs and all it needs.
     *
     * ⚠️ AND IT IS HERE RATHER THAN A `cohort()` RELATION ON `ClassSession`,
     * which LiveSessions must not have: the cohort is Learning's model, and one
     * module borrowing another's once is how the boundary stops being one.
     *
     * An id with no row is simply absent from the result — a group that was
     * deleted is not an error on a calendar that merely wanted to label a row.
     *
     * @param  list<int>  $cohortIds
     * @return array<int, string> keyed by cohort id
     */
    public function namesFor(array $cohortIds): array;

    /**
     * The public identifiers of EVERY group of this course, whatever its status.
     *
     * ⛔ IT EXISTS SO THAT «IS THIS COURSE SOLD AT ALL» CAN SEE A GROUP PRICE
     * (٠٣٦ · T012). A plan may name a group, `plans.coverage_uuid` is a `uuid`
     * column, and `Payments` may not turn a cohort id into one — that would be
     * `Payments` reading `cohorts`. Without this, a course sold ONLY through its
     * groups reads as «no price attached», and the free-enrolment door opens it
     * to anybody who asks.
     *
     * ⚠️ NO STATUS FILTER, AND THAT IS THE POINT. The question is whether a
     * price exists, not whether a seat is open today: a course whose only group
     * is full or closed is still a course that is sold, and filtering here would
     * hand it out free for as long as it stays that way.
     *
     * ⚠️ AND IT IS DELIBERATELY NOT THE BULK SHAPE THE REST OF THIS FILE USES.
     * Both callers hold exactly one course — the free-enrolment door and the
     * public course page — so a list-shaped signature here would be a parameter
     * every caller wraps and unwraps for nothing.
     *
     * @return list<string>
     */
    public function cohortUuidsFor(int $courseId): array;

    /**
     * The public identifiers of these groups, by internal id (٠٢٦).
     *
     * ⚠️ **THE TWIN OF {@see namesFor()}, AND IT EXISTS BECAUSE AN ID NEVER
     * LEAVES THE SERVER.** `lesson_cohort_scopes` stores internal ids, and the
     * authoring payload has to say WHICH groups an item was narrowed to so the
     * editor can tick them — `HasUuid` makes the uuid the only thing that may
     * travel. Bulk by signature like everything else here: a Resource runs once
     * per row, so a per-lesson lookup is the `ClassSessionResource` N+1 arriving
     * through yet another door.
     *
     * An id with no row is simply absent — a group that was deleted is not an
     * error on a screen that merely wanted to label a row.
     *
     * @param  list<int>  $cohortIds
     * @return array<int, string> keyed by cohort id
     */
    public function uuidsFor(array $cohortIds): array;

    /**
     * Groups with people in them that no live price reaches (٠٣٦ · FR-010 · FR-013).
     *
     * ⛔ ONE SPELLING FOR TWO READERS, AND FR-013 NAMES THAT AS THE
     * REQUIREMENT RATHER THAN AS TIDINESS: «والعددُ يُحسَبُ بتهجئةِ FR-002
     * نفسِها التي يقرؤها حارسُ ما قبلَ النشر (FR-010)». The pre-deploy
     * guard counts who loses their group when the gate bites; the teacher's
     * warning counts who loses their group when THIS edit lands. A warning that
     * says a number while the gate does otherwise is worse than no warning, so
     * the two read one method rather than two queries that agree today.
     *
     * The predicate is the offer's own: a GROUP cohort, not archived, carrying
     * at least one open `cohort_memberships` row, which `priceReaches()` says no
     * live price covers.
     *
     * ⚠️ «WITH MEMBERS» IS THE WHOLE FILTER, AND IT IS A REAL ROW. An empty
     * unlisted group is a group nobody was ever offered and nobody is in —
     * hiding it takes nothing from anybody. And the count comes from the
     * membership rows rather than from `cohorts.members_count`, because that
     * counter is a cache of them and the answer would then depend on whichever
     * column the reader happened to reach for.
     *
     * ⚠️ `null` IS THE PLATFORM, and it is what the pre-deploy guard passes. A
     * workspace id narrows it to one teacher, which is what the warning needs:
     * an edit in one workspace cannot move a group in another, and walking every
     * group on the platform to prove that is a cost that grows with the
     * platform on a screen one teacher opened.
     *
     * @return array<int, array{uuid: string, name: string, workspace_id: int, members: int}>
     *                                                                                        keyed by cohort id
     */
    public function unlistedCohortsWithMembers(?int $workspaceId = null): array;

    /**
     * Where a student of this course could ask to be MOVED (٠٣٦ · T118 · FR-019).
     *
     * ⛔ **ITS OWN READ, AND WITHOUT ONE FR-019 IS UNIMPLEMENTABLE.** The
     * transfer picker used to build its options out of the student PICKER's list
     * and then filter those again on `is_joinable` — and ٠٣٦ narrowed both:
     * {@see pickerCohortsFor()} DROPS a group no live price reaches, and
     * `Cohort::isJoinable()` now asks the price too. So the destination FR-019
     * is about — «a group that is open and has room but is not on sale» — is
     * deleted twice over before the screen sees it, and the requirement to mark
     * it could never fire.
     *
     * ⚠️ **STRUCTURALLY JOINABLE IS THE FILTER; THE PRICE IS A LABEL.** Open
     * and not full is what makes a room a possible destination; whether it is on
     * sale is what the officer deciding the transfer needs to KNOW, not a reason
     * to hide the option — a student asking to move into an unpriced group is a
     * request somebody can answer, and an option missing from a list is a
     * question nobody can ask.
     *
     * ⚠️ AND THE GROUP THE READER IS ALREADY IN IS NOT EXCLUDED HERE. The
     * caller knows which one that is; this method answers about the course.
     *
     * ⚠️ THE MEETING TIMES TRAVEL WITH IT, READ IN ONE CALL FOR THE WHOLE
     * LIST. «الأحد ٦م» is the fact a student picks a destination on — a
     * group named «المجموعة الثانية» says nothing — and asked per row it
     * would be one query per option inside a picker.
     *
     * @return list<array{uuid: string, name: string, schedule_preview: list<string>, is_on_sale: bool}>
     */
    public function transferDestinationsFor(int $courseId): array;
}
