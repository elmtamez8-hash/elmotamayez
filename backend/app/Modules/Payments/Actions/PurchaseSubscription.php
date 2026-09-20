<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Support\CoveredCourses;
use App\Modules\Payments\Support\PlanReach;
use App\Modules\Payments\Support\PurchaseBeneficiary;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;

/**
 * A student buys a subscription (T092 · FR-025 · FR-026).
 *
 * ⚠️ NOTHING IS ACTIVATED HERE. A manual bank transfer takes days to be
 * witnessed, so this writes an `Order(kind: subscription)` and stops;
 * {@see App\Modules\Payments\Listeners\ActivateSubscription} does the rest when
 * the money is approved or captured. Activating on purchase would be a month of
 * access handed out against a transfer that may never arrive.
 *
 * ⚠️ THE PLAN IS RESOLVED INSIDE THIS ACTION, NEVER BY ROUTE-MODEL BINDING.
 * `Plan` carries `BelongsToWorkspace`, which protects nothing on a buyer's path:
 * a student is a member of no workspace, `WorkspaceContext::id()` is null, and
 * `WorkspaceScope::apply()` adds no condition at all. An implicit `{plan}` would
 * resolve any teacher's plan, including an inactive or unpriced one.
 *
 * ⚠️ AND THERE IS NO PARTICIPATION GUARD, DELIBERATELY — the opposite of
 * `ListCreditPackages`, which refuses a stranger with a 403. That guard exists
 * because a credit package's price is `(approved rate + constants) × credits`,
 * so two totals solve for the teacher's settlement rate exactly. A plan's price
 * is a number a platform officer typed; it derives from nothing and reveals
 * nothing. Subscribing is also how a student STARTS with a teacher, so requiring
 * an enrolment first would close the door this feature exists to open.
 */
class PurchaseSubscription extends Action
{
    public function __construct(
        private readonly CohortDirectory $cohorts,
        private readonly CoveredCourses $covered,
        // 036 · FR-016 -- the same object `ListPlans` reads, so the screen and
        // this door cannot disagree about which plans reach a group.
        private readonly PlanReach $reach,
    ) {}

    /**
     * ⚠️ **الوسيطُ الأوّلُ هو من يُشترى له، لا من يدفع — والدَّورانِ افترقا في
     * ٢٠٢٦-٠٩-٠٨.** كانَ يُسمّى المشتريَ ويُكتَبُ في `user_id` بلا سؤال، فوليُّ أمرٍ
     * ضغطَ «اشترك» صارَ هو الطالبَ: اشتراكٌ وتسجيلٌ وعضويّةُ مجموعةٍ باسمِه، وابنُه
     * الذي دُفِعَ من أجلِه بلا شيء. {@see PurchaseBeneficiary} يحسمُ الدَّورَينِ عندَ
     * الباب، وهذا الإجراءُ يكتبُهما كما تكتبُهما {@see PurchaseCredits} منذُ ٠٢٤ —
     * تهجئةٌ واحدةٌ لسؤالٍ واحد.
     *
     * @param  string  $mode  `cohort` or `private` — the intent (FR-012)
     * @param  string|null  $cohortUuid  required with `cohort`, forbidden with `private`
     * @param  User|null  $grantedBy  من أنشأ الطلبَ نيابةً عن الطالب، أو `null` إن اشترى بنفسِه
     */
    public function handle(
        User $student,
        string $planUuid,
        string $mode = SubscriptionIntent::MODE_PRIVATE,
        ?string $cohortUuid = null,
        ?User $grantedBy = null,
    ): Order {
        /*
        | ⛔ THE TEACHER IS NEVER THE STUDENT — IN ANY WORKSPACE, THEIRS INCLUDED.
        |
        | Reported 2026-09-08: «اشترك في هذه المجموعة» on a public course page was
        | open to the course's own owner. Nothing in this Action asked WHO was
        | buying — it checked the plan, the group, the transfer rule and the
        | pending order, and every one of those is a question about the thing
        | being bought.
        |
        | ⚠️ ON `$student`, NEVER ON `$grantedBy`. A guardian buying for their
        | child is the ordinary case and the child is who lands in `enrollments`;
        | a guard on the payer would refuse the wrong person and let the real one
        | through. The mirror of {@see PurchaseBeneficiary}'s own rule.
        |
        | The third of three doors — the free enrolment and the paid course order
        | carry the same predicate in their controllers.
        */
        if ($student->teachesOnPlatform()) {
            throw new DomainException('هذا الحسابُ حسابُ مدرّسٍ على المنصّة، والمدرّسُ لا يشتركُ في الكورسات.');
        }

        $plan = Plan::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $planUuid)
            ->sellable()
            ->first();

        if ($plan === null) {
            // One sentence for «no such plan», «switched off» and «not priced
            // yet». The three are indistinguishable to a buyer and telling them
            // apart would say which teachers have plans awaiting a price.
            throw new DomainException('هذه الباقة غير متاحة.');
        }

        $this->guardCoverageStillExists($plan);
        $this->guardModeMatchesPlan($plan, $mode);

        $cohort = $mode === SubscriptionIntent::MODE_COHORT
            ? $this->resolveCohort($plan, $cohortUuid, $student)
            : null;

        $this->guardNoPendingOrder($student, (int) $plan->workspace_id, $grantedBy !== null);

        // ٠٢٧ · T075 — واحدةٌ لا اثنتانِ: الكورسُ الذي يُختمُ على الطلبِ هو الذي
        // يُقرَأُ منهُ المدرّسُ، وتهجئتانِ للسؤالِ نفسِهِ تفترقانِ عندَ أوّلِ تعديل.
        $courseId = $this->coverageCourseId($plan) ?? $cohort['course_id'] ?? null;

        $teacher = $this->teacherOf($plan, $courseId);

        /*
        | ⛔ THE SHAPE IS BRANCHED ON HERE, AND «HERE» IS BEFORE ANY ORDER EXISTS.
        | ٠٣٦ gave a plan two possible shapes — a window of days, or a number of
        | hours — and exactly one of them is ever filled. The snapshot has to say
        | which, because everything downstream reads the snapshot rather than the
        | plan row: a manual transfer takes days to clear and the teacher may
        | legitimately re-shape the plan inside that lag.
        |
        | ⛔ WHAT HAPPENS WITHOUT THIS BRANCH, IN FULL (٠٣٦ · T030). A session
        | plan's `duration_days` is NULL; `(int) null` is **0**; the snapshot then
        | carries a duration of zero; `ActivateSubscription` computes
        | `$starts->addDays(0)`, so the subscription's end date EQUALS its start
        | date — **a subscription that has expired the instant it was activated**.
        | The student has paid, the officer has approved, the row exists, and
        | every screen is correct about a window that is empty. Nothing throws,
        | nothing is logged, and nobody finds out until the student cannot open
        | what they bought.
        |
        | ⚠️ AND THE REFUSAL DOES NOT BELONG IN THE DTO. A guard in
        | {@see SubscriptionIntent} would fire inside a deserialiser that is
        | called after the money has committed; its own docblock now carries that
        | argument in full.
        */
        $sessionCount = $plan->session_count === null ? null : (int) $plan->session_count;
        $durationDays = $sessionCount !== null || $plan->duration_days === null
            ? null
            : (int) $plan->duration_days;

        $intent = new SubscriptionIntent(
            planUuid: (string) $plan->uuid,
            planTitle: (string) $plan->title,
            durationDays: $durationDays,
            sessionCount: $sessionCount,
            sessionType: $plan->session_type->value,
            mode: $mode,
            cohortUuid: $cohort === null ? null : $cohortUuid,
            cohortName: $cohort['name'] ?? null,
            teacherUuid: $teacher === null ? null : (string) $teacher->uuid,
            teacherName: $teacher === null ? null : (string) $teacher->name,
        );

        $order = Order::create([
            'workspace_id' => $plan->workspace_id,
            // ⚠️ الطالبُ، لا الدافع. ترويسةُ هجرةِ `granted_by` تقولُها بنصِّها:
            // «صاحبُ الطلبِ والرصيد، حتّى حينَ لم يلمسْ لوحةَ مفاتيح».
            'user_id' => $student->getKey(),
            // A course-scoped plan stamps its course; a workspace-scoped one that
            // was bought against a named group stamps THAT group's course, so the
            // officer's «الكورس» column is not blank for the very orders spec 027
            // creates. It is still null for a workspace plan bought for private
            // hours — which is why FR-011's duplicate guard keys on the teacher
            // rather than on this column.
            'course_id' => $courseId,
            'kind' => OrderKind::Subscription,
            // ⚠️ THE PRICE IS COPIED ONTO THE ORDER AND THE SUBSCRIPTION IS LATER
            // BUILT FROM THE ORDER, NOT FROM THE PLAN. A manual transfer takes
            // days; the plan can legitimately be repriced inside that lag, and
            // reading it at activation would charge this student a number they
            // were never shown (FR-030).
            'amount_minor' => (int) $plan->price_minor,
            'currency' => $plan->currency,
            'provider' => 'manual',
            'status' => 'pending',
            /*
            | ⚠️ THE INTENT TRAVELS ON THE ORDER'S METADATA, NOT IN COLUMNS.
            | `orders` is shared by four kinds and a `plan_id` on it would be
            | null for three of them — and the same is true of every key in the
            | snapshot beside it.
            |
            | ⚠️ AND EVERY KEY IS WRITTEN FROM WHAT THIS ACTION RESOLVED, NEVER
            | FROM THE REQUEST BODY. The buyer names two uuids; the names, the
            | duration, the session type and the teacher are read off the rows
            | those uuids turned out to mean.
            */
            'metadata' => $intent->toMetadata(),
        ]);

        if ($grantedBy !== null) {
            /*
            | ليسَ في `$fillable` عمداً — انظرِ النموذج. حقيقةٌ تدقيقيّةٌ تُكتَبُ هنا
            | مرّةً ولا تُعادُ: إعادةُ ختمِها عندَ تعديلٍ لاحقٍ تنقلُ فعلاً مسجَّلاً
            | إلى آخرِ من لمسَ الصفّ. و{@see PurchaseCredits} تكتبُها بالشكلِ نفسِه.
            */
            $order->forceFill(['granted_by' => $grantedBy->getKey()])->save();
        }

        return $order;
    }

    /**
     * The intent has to agree with what the plan actually sells (FR-008 · FR-012).
     *
     * A group plan is priced for a room of eight and a private plan for one
     * student; letting «حصص خاصّة» ride a group plan would sell one-to-one hours
     * at the group rate, and the mismatch would only surface at the charge
     * branch, weeks later, as a credit nobody could explain.
     */
    private function guardModeMatchesPlan(Plan $plan, string $mode): void
    {
        $expected = $plan->session_type === ClassSessionType::Group
            ? SubscriptionIntent::MODE_COHORT
            : SubscriptionIntent::MODE_PRIVATE;

        if ($mode !== $expected) {
            throw new DomainException($plan->session_type === ClassSessionType::Group
                ? 'هذه الباقة لحصص جماعية، فاختر مجموعة.'
                : 'هذه الباقة لحصص فردية، فاختر الحصص الخاصة.');
        }
    }

    /**
     * The chosen group, proved to belong to what the plan covers.
     *
     * ⚠️ NOT `resolveCohortId($uuid, $courseId)`, BECAUSE THERE IS NO COURSE ID
     * TO PASS. `coverageCourseId()` answers null for a workspace-wide plan, and
     * a null or zero there would resolve any group uuid on the platform. The
     * group is looked up on its own and then has to prove two things: it belongs
     * to the plan's workspace, and — for a course-scoped plan — to that course.
     *
     * ⚠️ ONE SENTENCE FOR ALL THE FAILURES. «Does not exist», «belongs to another
     * teacher», «is a private 1:1 room» and «is full» answer identically, or the
     * refusal becomes an oracle about groups the buyer may not see.
     *
     * @return array{id: int, course_id: int, workspace_id: int, name: string, course_uuid: string, is_joinable: bool}
     */
    private function resolveCohort(Plan $plan, ?string $cohortUuid, User $student): array
    {
        $cohort = $cohortUuid === null ? null : $this->cohorts->describeGroupCohort($cohortUuid);

        if ($cohort === null) {
            throw new DomainException('هذه المجموعة لم تعد متاحة للانضمام.');
        }

        // ⚠️ `courseUuid()`, not `coverage_uuid`: on a group plan the raw column is a
        // cohort uuid and would never equal a course uuid, so the comparison would
        // refuse every group the plan was written for.
        $planCourseUuid = $this->covered->courseUuid($plan);

        /*
        | ⛔ ٠٣٦ · FR-015 — THE THIRD ARM, AND WITHOUT IT A GROUP PLAN SOLD ANY
        | GROUP IN ITS COURSE. `courseUuid()` resolves a cohort-covered plan to
        | its group's COURSE, which is right for enrolment and far too wide for
        | this door: «مجموعة الجمعة» priced at double, because it is four
        | students, was buyable by anyone standing on «مجموعة السبت» of the same
        | course -- and the other way round, so the intensive group could be had
        | at the ordinary group's price. Both directions, no error anywhere, and
        | the order's own snapshot would name the group that was actually chosen.
        |
        | So a plan that NAMES a group is proved against that group and nothing
        | else. Coverage that names a course or a workspace still reaches every
        | group inside it -- that is the inheritance FR-015 keeps.
        */
        $namesThisCohort = $plan->coverage_type !== PlanCoverage::Cohort
            || $plan->coverage_uuid === $cohort['uuid'];

        /*
        | ⛔ AND THE OTHER HALF OF FR-015: A GROUP THAT WAS PRICED APART NO LONGER
        | INHERITS. Without this the replacement lived on the SCREEN alone -- the
        | course's month was hidden from the buyer standing on «مجموعة الجمعة»
        | and still bought, by anyone who had the plan's uuid from the course page
        | or a bookmark, at the price the teacher had deliberately moved away from.
        |
        | The predicate is `PlanReach::ownPlanIds()`, which is the object the
        | listing reads; asked here in its own words the two would agree until the
        | first time either moved.
        |
        | ⚠️ EXISTENCE, NOT SELLABILITY. A group whose own plan is written and
        | unpriced buys nothing at all rather than falling back -- the fallback is
        | what would sell it at the price it was moved away from, in silence.
        */
        $ownPlanIds = $this->reach->ownPlanIds((int) $plan->workspace_id, $cohort['uuid']);

        $notPricedApart = $ownPlanIds === []
            || in_array((int) $plan->getKey(), $ownPlanIds, true);

        $covered = $namesThisCohort
            && $notPricedApart
            && $cohort['workspace_id'] === (int) $plan->workspace_id
            && ($planCourseUuid === null || $cohort['course_uuid'] === $planCourseUuid);

        if (! $covered) {
            throw new DomainException('هذه المجموعة لم تعد متاحة للانضمام.');
        }

        /*
        | ⚠️ THE MEMBERSHIP IS ASKED BEFORE JOINABILITY, AND THE ORDER IS THE
        | WHOLE OF FR-028. A student renewing on day 28 sits in a group that is
        | full — of them and their classmates — so `is_joinable` is false for the
        | very person the requirement guarantees a renewal to. There is nothing
        | for them to join: their membership is already open, and the renewal
        | extends the subscription behind it.
        */
        $current = $this->cohorts->openMembershipCohortId($student, $cohort['course_id']);

        if ($current === $cohort['id']) {
            return $cohort;
        }

        if ($current !== null) {
            /*
            | ⚠️ REFUSED AT PURCHASE, NOT AT ACTIVATION.
            | `CohortMembershipWriter` does not defend against this: an open
            | membership elsewhere in the course is CLOSED and the new one
            | opened, so buying the cheapest plan naming another group is a
            | transfer with no teacher decision behind it, recorded in the audit
            | as a join. Refusing at activation instead would refuse after the
            | money had already been taken.
            */
            throw new DomainException('أنت في مجموعة أخرى من هذا الكورس، والانتقال يكون بطلب نقل.');
        }

        if (! $cohort['is_joinable']) {
            throw new DomainException('هذه المجموعة لم تعد متاحة للانضمام.');
        }

        return $cohort;
    }

    /**
     * One pending subscription order per buyer per teacher (FR-011).
     *
     * ⚠️ KEYED ON THE WORKSPACE, NOT ON `course_id`. That column is null for
     * every workspace-coverage plan bought for private hours, and
     * `where('course_id', null)` matches every one of them the buyer ever
     * placed — a guard that refuses the wrong people and lets the right ones
     * through. The teacher is never null and is what the requirement protects:
     * two transfers to one teacher for one thing.
     */
    private function guardNoPendingOrder(User $student, int $workspaceId, bool $onBehalf): void
    {
        $exists = Order::query()
            ->withoutWorkspaceScope()
            ->where('user_id', $student->getKey())
            ->where('workspace_id', $workspaceId)
            ->where('kind', OrderKind::Subscription)
            ->awaitingDecision()
            ->exists();

        if ($exists) {
            /*
            | ⚠️ **«لديك» تصيرُ كذبةً حينَ يشتري غيرُك لك.** الشرطُ على الطالبِ —
            | وهو الصحيح، فالمطلوبُ طلبٌ معلّقٌ واحدٌ لكلِّ طالبٍ عندَ كلِّ مدرّس —
            | لكنّ الجملةَ تُقالُ لمن يقفُ على الشاشة. ووليُّ أمرٍ يقرأُ «لديك طلب»
            | عن طلبٍ قدّمَه ابنُه بنفسِه يبحثُ في طلباتِه هو عن شيءٍ ليسَ فيها.
            */
            throw new DomainException($onBehalf
                ? 'لهذا الطالب طلب قيد المراجعة على هذا الكورس.'
                : 'لديك طلب قيد المراجعة على هذا الكورس.');
        }
    }

    /**
     * The teacher, read once and frozen on the order (FR-015).
     *
     * ⛔ FROM THE COURSE'S CREATOR, AND IT USED TO BE THE WORKSPACE'S OWNER —
     * WHICH PUT A DIFFERENT PERSON'S NAME ON EVERY SCREEN THAT READ THE ORDER.
     * Measured on production 2026-09-20 (٠٢٧ · T075): the student subscribed
     * from a page saying «Sami Teacher» — `PublicCourseDetailResource` reads
     * `$course->creator` — while this method answered «Nour Owner», so the
     * officer's queue, the snapshot and the activation notification all named
     * somebody the buyer had never been shown. Owner-decided 2026-09-20: the
     * teacher profile is the answer, so the reading follows the screen.
     *
     * ⚠️ AND THE TWO AGREED IN FIVE WORKSPACES OUT OF SIX, WHICH IS WHY IT SAT
     * UNSEEN. The one that broke was «Nour Academy» — an academy owned by one
     * person and taught by another — and that is not an exotic fixture, it is
     * the shape this product is sold into. A rule that holds by coincidence of
     * data holds until the first customer who is shaped differently.
     *
     * ⚠️ THE OWNER STAYS AS THE FALLBACK, NOT AS THE RULE. A workspace-coverage
     * plan bought for private hours names no course at all — `coverageCourseId()`
     * is null and there is no cohort to borrow one from — and a null teacher
     * would print «مدرّسك» into the notification where a name belongs.
     *
     * ⚠️ AND IT IS STILL READ ONCE AND FROZEN. `orders` has no teacher column,
     * and deriving one per row in the officer's queue would be a query inside a
     * Filament column — an N+1 by construction. What changed is the source, not
     * the freezing.
     */
    private function teacherOf(Plan $plan, ?int $courseId): ?User
    {
        $creator = $courseId === null
            ? null
            : Course::query()
                ->withoutWorkspaceScope()
                ->whereKey($courseId)
                ->first()?->creator;

        return $creator ?? Workspace::query()
            ->withoutGlobalScopes()
            ->whereKey($plan->workspace_id)
            ->first()?->owner;
    }

    /**
     * A course-scoped plan whose course has been withdrawn sells nothing.
     *
     * The subscription itself survives a course being unpublished — coverage
     * simply drops it and the rest stays (data-model §٧) — but a plan that covers
     * ONE course and nothing else would sell a month of access to nothing at all.
     */
    private function guardCoverageStillExists(Plan $plan): void
    {
        $uuid = $this->covered->courseUuid($plan);

        if ($plan->coverage_type === PlanCoverage::Workspace) {
            return;
        }

        /*
        | ⛔ A COHORT UUID IS NOT A COURSE UUID, and this method used to look both
        | up in `courses` — so every group plan matched zero rows and was refused
        | «هذه الباقة غير متاحة» about a plan the teacher could see on their own
        | screen. `CoveredCourses` is what translates one into the other, in the
        | one place that knows how.
        |
        | And a coverage that no longer resolves at all — the group archived, the
        | course deleted — falls into the same refusal, which is the correct one:
        | there is nothing left to sell.
        */
        $live = $uuid !== null && Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $plan->workspace_id)
            ->where('uuid', $uuid)
            ->where('status', 'published')
            ->exists();

        if (! $live) {
            throw new DomainException('هذه الباقة غير متاحة.');
        }
    }

    // ⛔ Same defect as the guard above, same fix: the uuid on a group plan names a
    // COHORT, so the old query returned null and `orders.course_id` was left empty
    // on every group purchase — after which nothing downstream knew which course
    // had been bought.
    private function coverageCourseId(Plan $plan): ?int
    {
        return $this->covered->coverageCourseId($plan);
    }
}
