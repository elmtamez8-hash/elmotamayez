<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\GetStudentSchedule;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Http\Resources\ChildSessionResource;
use App\Modules\LiveSessions\Http\Resources\ClassSessionResource;
use App\Modules\LiveSessions\Http\Resources\SessionBookingResource;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\CohortSessionVisibility;
use App\Modules\LiveSessions\Support\GuardianChild;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own timetable, across every teacher.
 *
 * No policy call on {@see self::index()} and {@see self::next()}: there is no
 * resource to authorise against. The authorisation IS the identity — the action
 * only ever reads rows belonging to the authenticated user, and neither route
 * takes a parameter by which to ask for anyone else's.
 *
 * ⚠️ {@see self::children()} IS THE ONE EXCEPTION AND IT CARRIES ITS OWN GUARD
 * (029 · FR-020). It names a student, so the sentence above stops being true of
 * this class the moment it is read as covering every method — a guardian asks
 * for somebody else's timetable by definition. What stands in for the identity
 * there is the guardian relation PLUS the `schedule` permission, resolved in
 * {@see GuardianChild} before the Action is reached. It is a SEPARATE route
 * rather than an optional `?student=` on `index()` for exactly that reason: a
 * parameter that is usually absent is a guard that is usually not exercised.
 */
class ScheduleController extends Controller
{
    public function index(Request $request, GetStudentSchedule $action): JsonResponse
    {
        $bookings = $action->handle($this->currentUser($request));

        return response()->json(['data' => SessionBookingResource::collection($bookings)]);
    }

    /**
     * One child's upcoming sessions, read by their guardian (029 · US3).
     *
     * ⚠️ IT CALLS `GetStudentSchedule` AS IT STANDS, AND WRITES NO SECOND ACTION.
     * «A student's upcoming sessions» is one question; a mirrored copy of that
     * Action would be a second answer to it, and the two would drift at the first
     * change to either — which is the defect this repository has paid for with
     * `BookingEligibility`'s host check and with `ListLeaderboardScopes`.
     *
     * ⚠️ THE EAGER LOAD DROPS THE SCOPE AT EVERY LEVEL, AND WITHOUT THAT THE
     * PAYLOAD IS A GREEN 200 FULL OF NULLS. The Action bypasses the scope for the
     * booking and the session; `course` and `teacherProfile` are loaded here, and
     * both models carry `BelongsToWorkspace` — so under a guardian whose
     * `last_workspace_id` resolves to some OTHER workspace (an academy founder
     * who is also a parent, say) a scoped nested load returns null for every one
     * of them and the card renders a lesson with no subject and no teacher. Same
     * shape spec 024 found five times in the payments approval chain.
     *
     * ⚠️ AND IT IS A LOAD RATHER THAN A LAZY READ IN THE RESOURCE: a Resource
     * runs once per row, so reaching for `$session->course` from inside one is an
     * N+1 by construction — and an N+1 that would each carry the scope.
     */
    public function children(
        Request $request,
        GetStudentSchedule $action,
        GuardianDirectory $guardians,
    ): JsonResponse {
        $child = GuardianChild::named(
            $request,
            $this->currentUser($request),
            $guardians,
            GuardianPermission::Schedule,
        );

        // ⚠️ NO SECOND EAGER LOAD HERE. The Action loads the course and the
        // teacher — scope dropped at every level — because the STUDENT's own
        // timetable needs them too. Loading them again beside it would be two
        // spellings of one requirement, and the copy that gets forgotten is the
        // one that answers a green 200 full of nulls.
        return response()->json(['data' => ChildSessionResource::collection($action->handle($child))]);
    }

    public function next(Request $request, GetStudentSchedule $action): JsonResponse
    {
        $booking = $action->next($this->currentUser($request));

        $session = $booking?->classSession;

        if ($booking === null || $session === null) {
            // Not a 404. "You have no upcoming sessions" is an answer, and the
            // screen shows an empty state for it rather than an error (FR-055).
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => SessionBookingResource::make($booking),
            // Counted on the server. A countdown built from the browser's clock
            // shows a time that does not exist on a machine whose clock is off
            // (SC-016).
            'seconds_until_start' => max(0, (int) now()->diffInSeconds($session->starts_at, false)),
        ]);
    }

    /**
     * The next session of ONE course, for the header of its page (US2 · FR-015).
     *
     * ⚠️ NOT `next()` WITH A FILTER. That one reads the student's own BOOKINGS
     * across every teacher they study with — the right answer for a timetable and
     * the wrong shape for a course header, which must name the next lesson of
     * this course whether or not a seat has been taken yet. A student who has not
     * booked is exactly the student the header exists for.
     *
     * ⚠️ THE BOUND IS `ends_at`, NOT `starts_at`. Bounded by the start, the header
     * loses the session at the moment it begins — during the very minutes the
     * join button is the only thing on the page worth pressing. The window is
     * widened by the join allowance for the same reason `join_open` exists.
     *
     * ⚠️ AND `cancelled` IS EXCLUDED BY STATUS RATHER THAN BY THE WINDOW.
     * `CancelClassSession` does not stamp `room_closed_at`, so `joinWindowCovers()`
     * still says yes on a cancelled session — the 018 defect that sent a teacher
     * who had just cancelled a lesson to a raw error page.
     */
    public function nextForCourse(Request $request, Course $course, EnrollmentDirectory $enrollments): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $enrollments->hasActiveEnrollment($user, (int) $course->getKey())) {
            return $this->notEnrolled();
        }

        $window = app(SessionSettings::class)->joinWindowMinutes();

        $session = ClassSession::query()
            ->where('course_id', $course->getKey())
            // Q3 — the header counts down to a lesson this student is actually
            // invited to. One spelling for every door (FR-025ج).
            ->tap(fn ($query) => CohortSessionVisibility::apply($query, $user))
            ->whereIn('status', [ClassSessionStatus::Scheduled, ClassSessionStatus::Live])
            ->where('ends_at', '>=', now()->subMinutes($window))
            ->orderBy('starts_at')
            // The Resource asks every published session where its recording went,
            // and reads the viewer's booking off the loaded relation.
            ->with(['course', 'bookings', 'recordingLesson'])
            ->first();

        if ($session === null) {
            // Not a 404 and not an empty list. «لا حصّةَ قادمة» is an ANSWER, and
            // the header renders a sentence for it rather than an empty countdown
            // (FR-015).
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => ClassSessionResource::make($session),
            // Counted on the server, like `next()` above: a countdown built from
            // the browser's clock shows a time that does not exist on a machine
            // whose clock is off (SC-016).
            'seconds_until_start' => max(0, (int) now()->diffInSeconds($session->starts_at, false)),
            // ⚠️ ON THE MODEL NOW, BECAUSE 029'S TIMETABLE CARD ASKS THE SAME
            // QUESTION. A private copy here and a second one in the Resource
            // would drift the day an operator moves the join window.
            'seconds_until_join_open' => $session->secondsUntilJoinOpen(now()),
        ]);
    }

    /**
     * Every session of ONE course, for its page's sessions tab (US2 · FR-016).
     *
     * ⚠️ IT EXISTS BECAUSE `GET /class-sessions` ANSWERS A REAL STUDENT `403`,
     * AND THAT IS NOT A BUG THERE. `ClassSessionPolicy::viewAny()` asks for
     * `SESSIONS_VIEW`, and a student holds no spatie team id — they are a member
     * of no workspace, so `WorkspaceContext::id()` is null and every `can()`
     * below it is false. Measured, not assumed: the tab built against that route
     * caught the refusal into an empty list and told a student with a lesson
     * every week «لا حصص في هذه المادّة بعد». The endpoint that serves them is
     * one whose guard is the ENROLMENT, exactly as `nextForCourse()` above is,
     * and this is the only spelling of that question in the module.
     *
     * ⚠️ AND THE WINDOW IS BOUNDED FROM BOTH ENDS — «THE OLDEST FIFTY» DEFECT.
     * `/class-sessions` paginates at fifty ASCENDING, so an unbounded read of a
     * course in its second term returns its first fifty lessons and no upcoming
     * one at all, including the session the page's own header is counting down
     * to. Two bounded reads instead: the nearest ahead, and the most recent
     * behind. Bounded on `ends_at` so a lesson in progress counts as ahead,
     * which is where the join button is.
     *
     * ⚠️ AND THE CAP IS DECLARED RATHER THAN SILENT. A course with more than
     * fifty either side loses the far end; a term is well under it, and the
     * upgrade is paging this tab, not raising a number nobody can see.
     */
    public function forCourse(Request $request, Course $course, EnrollmentDirectory $enrollments): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $enrollments->hasActiveEnrollment($user, (int) $course->getKey())) {
            return $this->notEnrolled();
        }

        $base = fn (): Builder => ClassSession::query()
            ->where('course_id', $course->getKey())
            /*
             | ⚠️ Q3 IS ENFORCED HERE, BECAUSE THIS IS THE STUDENT'S REAL
             | DISCOVERY DOOR. `/class-sessions` answers a real student `403`, so
             | filtering there alone would have been a guard on an endpoint no
             | student can reach — correct-looking, tested, and never executed.
             */
            ->tap(fn ($query) => CohortSessionVisibility::apply($query, $user))
            // The Resource asks every published session where its recording went,
            // and reads the viewer's own booking off the loaded relation.
            ->with(['course', 'bookings', 'recordingLesson']);

        $ahead = $base()->where('ends_at', '>=', now())->orderBy('starts_at')->limit(50)->get();
        $behind = $base()->where('ends_at', '<', now())->orderByDesc('starts_at')->limit(50)->get();

        return response()->json([
            'data' => ClassSessionResource::collection($behind->reverse()->concat($ahead)->values()),
        ]);
    }

    /**
     * `403`, and the wire value `LessonAccess::NOT_ENROLLED` carries — spelled
     * here rather than imported: LiveSessions asks Learning its questions
     * through {@see EnrollmentDirectory} and knows none of its classes.
     */
    private function notEnrolled(): JsonResponse
    {
        return response()->json([
            'message' => 'لا تملك تسجيلاً في هذا الكورس.',
            'code' => 'not_enrolled',
        ], 403);
    }

    /**
     * How long until the door opens — `0` for open now, `null` for never again.
     *
     * ⚠️ WITHOUT IT THE BUTTON NEVER APPEARS ON A PAGE LEFT OPEN. `join_open` is
     * a boolean answered once, at fetch: a student who opens the course twenty
     * minutes early watches the countdown reach «بدأت الآن» while the footer
     * still reads «يُفتح الدخول قبل الموعد» — for ever, until they reload. That
     * is FR-015's «حين» half unimplemented, and it is strictly worse than
     * guessing the window in the browser, which at least changes its mind as the
     * clock ticks.
     *
     * A second countdown rather than a poll, and seeded here for the same reason
     * the first one is: the browser may only tick a number down, never derive it
     * from `starts_at` and a clock that may be an hour out (SC-016).
     *
     * `null` is a distinct answer from a large number — a closed room and a
     * window already past both mean «this will not open», and a client counting
     * down to one of those would draw the button eventually.
     */
}
