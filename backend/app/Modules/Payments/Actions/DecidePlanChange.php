<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Enums\PlanChangeStatus;
use App\Modules\Payments\Exceptions\PlanWouldHideCohorts;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\PlanChangeRequest;
use App\Modules\Payments\Support\PlanShape;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Support\CountedNoun;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The platform decides. Approval writes a NEW plan and retires the old one
 * (٠٣٦, owner decision 2026-09-19).
 *
 * ⛔ A NEW ROW, NOT AN EDIT, AND THE REASON IS WHAT A SUBSCRIPTION NAMES.
 * `subscriptions.plan_id` points at the plan that was bought, and every screen a
 * subscriber reads gets its title and its terms from there. Editing the row in
 * place changes what somebody who bought twelve sessions reads about the thing
 * they bought — quietly, months later. The old plan is switched off instead: it
 * stops being sellable, it stays readable for everyone holding one, and
 * `approved_plan_id` is the only thread between the two afterwards.
 *
 * ⛔ AND THE PRICE IS SET BY {@see SetPlanPrice}, NEVER BY A `fill()`.
 * `price_minor` is not `$fillable` — mass assignment discards it in silence, so
 * an approval that wrote it that way would create the new plan UNPRICED, invisible
 * to every catalogue, while telling the teacher their request was approved.
 *
 * ⚠️ THE DECISION IS AN ATOMIC CLAIM. Two officers opening the same queue is
 * ordinary, and a read-then-write there approves twice: two new plans, the old
 * one retired once, and a teacher with a duplicate on sale. One conditional
 * UPDATE on `status` is both the check and the claim — the seat idiom, and never
 * `lockForUpdate()`, which is a no-op on SQLite.
 *
 * ⚠️ AND THE TEACHER'S WORKSPACE IS WRITTEN OUT, NOT INHERITED FROM THE WRITER.
 * `BelongsToWorkspace` auto-fills that column from the CONTEXT — and a platform
 * officer's context falls back to their own `users.last_workspace_id` like
 * anybody else's, so left to the trait the approved plan lands in the officer's
 * catalogue and in no teacher's, with no error anywhere (٠٢٤). Naming the column
 * explicitly is what holds, because the trait defers to a value already set.
 *
 * ⚠️ AND THERE IS NO `forWorkspace()` WRAPPER HERE, WHICH AN EARLIER VERSION OF
 * THIS CLASS HAD WITH A COMMENT CALLING IT THE GUARD. **Measured by deleting it:
 * every case stayed green.** Nothing inside reads the context — the column is
 * explicit, `SetPlanPrice` says in its own docblock that its caller unscopes for
 * it, and saving an already-loaded model applies no scope. A wrapper that guards
 * nothing reads to the next person as one that does, and is the first thing they
 * trust instead of the line that actually holds.
 */
class DecidePlanChange extends Action
{
    public function __construct(
        private readonly DispatchNotification $notify,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function handle(
        PlanChangeRequest $request,
        User $officer,
        bool $approve,
        ?string $reason = null,
        bool $acknowledgeHiddenCohorts = false,
    ): PlanChangeRequest {
        if (! $officer->can(Permissions::PLANS_PRICE)) {
            throw new DomainException('تعديل ما سعّرته المنصّة قرار المنصّة.');
        }

        /*
        | ⛔ **THE WHOLE DECISION IS ONE TRANSACTION, AND FR-013 IS WHY.** The
        | status claim below used to stand outside any transaction, above a write
        | that could fail — so a throw in `applyTo()` left the request recorded
        | as APPROVED with no plan written and no way back: exactly the
        | `claimForGrading()` defect this tree already records, where a refusal
        | after a claim strands the row in the state the refusal exists to
        | prevent. The FR-013 warning IS such a throw, by design, so it could not
        | be added without this.
        |
        | ⚠️ THE CLAIM IS STILL ATOMIC. A conditional `UPDATE … WHERE status =
        | pending` inside a transaction takes the row's lock, so a second officer
        | blocks until the first commits and then matches zero rows — the same
        | answer, and the loser now also gets the first officer's whole decision
        | rolled back or committed as one thing rather than half of it.
        */
        return DB::transaction(fn (): PlanChangeRequest => $this->decide(
            $request,
            $officer,
            $approve,
            $reason,
            $acknowledgeHiddenCohorts,
        ));
    }

    private function decide(
        PlanChangeRequest $request,
        User $officer,
        bool $approve,
        ?string $reason,
        bool $acknowledgeHiddenCohorts,
    ): PlanChangeRequest {
        $status = $approve ? PlanChangeStatus::Approved : PlanChangeStatus::Rejected;

        $claimed = PlanChangeRequest::query()
            ->withoutWorkspaceScope()
            ->whereKey($request->getKey())
            ->where('status', PlanChangeStatus::Pending->value)
            ->update([
                'status' => $status->value,
                'decided_by' => (int) $officer->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new DomainException('هذا الطلب حُسِم بالفعل.');
        }

        $request->refresh();

        $hidden = $approve ? $this->applyTo($request, $acknowledgeHiddenCohorts) : [];

        $this->tellTeacher($request, $approve, $hidden);

        return $request->refresh();
    }

    /**
     * Write the plan the request asked for, and retire the one it names.
     *
     * ⚠️ THE OLD PLAN IS SWITCHED OFF, NEVER DELETED — `subscriptions.plan_id`
     * points at it and a student's own subscription must keep naming what they
     * bought. This is the same reason `PlanResource` refuses deletion outright.
     */
    /**
     * @return list<string> the names of the groups this approval took out of
     *                      the offer — empty when it took none
     */
    private function applyTo(PlanChangeRequest $request, bool $acknowledgeHiddenCohorts): array
    {
        $old = Plan::query()->withoutWorkspaceScope()->whereKey($request->plan_id)->first();

        if ($old === null) {
            // The plan went away between the ask and the decision. The request is
            // recorded as approved — the officer did agree — and nothing is
            // written, because there is nothing left to replace.
            return [];
        }

        return DB::transaction(function () use ($request, $old, $acknowledgeHiddenCohorts): array {
            $workspaceId = (int) $old->workspace_id;

            /*
            | ⛔ ٠٣٦ · FR-013, ON THE OFFICER'S SIDE — AND THIS IS WHERE THE
            | WRITE ACTUALLY HAPPENS. A teacher may not move a plan the platform
            | priced; the way through is this request, and approving it is what
            | narrows the coverage or retires the old plan. So the edit that can
            | drop a group with students in it out of every picker arrives HERE,
            | through a door the teacher's own warning never passes.
            |
            | ⚠️ AND THE WARNING BELONGS TO THIS MOMENT RATHER THAN TO THE ASK.
            | Nothing is written when the teacher submits a request, so a count
            | there would be a guess about a row that does not exist — the
            | modelling {@see SavePlan} refuses to do. Here the write is real, so
            | the gate is read before it and again after it and the difference is
            | measured, exactly as it is on the teacher's own door.
            |
            | ⚠️ THE TEACHER'S WORKSPACE, NOT THE OFFICER'S. Taken from the plan
            | being replaced: an officer's context falls back to their own
            | `users.last_workspace_id`, which would count groups in the wrong
            | catalogue and report zero for the one being changed.
            */
            $before = $this->cohorts->unlistedCohortsWithMembers($workspaceId);

            $plan = new Plan;

            $plan->fill([
                'workspace_id' => (int) $old->workspace_id,
                'title' => (string) $old->title,
                'duration_days' => $request->requested_duration_days,
                'session_count' => $request->requested_session_count,
                'session_type' => $request->requested_session_type,
                'coverage_type' => $request->requested_coverage_type,
                'coverage_uuid' => $request->requested_coverage_uuid,
                'currency' => (string) $old->currency,
                'is_active' => true,
            ]);

            $plan->save();

            /*
            | ⚠️ THE PRICE THE TEACHER ASKED FOR, OR NONE AT ALL. A request
            | that named no number is a teacher asking for a new shape and
            | leaving the price to the platform, which is the ordinary
            | arrangement (FR-025) — so the new plan lands in the pricing
            | queue exactly as a freshly written one does. Carrying the OLD
            | price across would be the platform pricing a thing it has not
            | seen, which is the hole this whole flow exists to close.
            */
            if ($request->requested_price_minor !== null) {
                app(SetPlanPrice::class)->handle($plan, (int) $request->requested_price_minor);
            }

            $old->forceFill(['is_active' => false])->save();

            $request->forceFill(['approved_plan_id' => (int) $plan->getKey()])->save();

            $hidden = array_diff_key(
                $this->cohorts->unlistedCohortsWithMembers($workspaceId),
                $before,
            );

            /*
            | ⚠️ THE DIFFERENCE, NEVER THE AFTER-READ ALONE. A group that was
            | already dark before this decision — its own plan sitting unpriced
            | while its course's is live — is not this approval's doing, and
            | naming it would teach the officer to click past a warning that is
            | usually wrong.
            |
            | ⚠️ AND THE THROW ROLLS BACK THE STATUS CLAIM TOO, because
            | {@see handle()} wraps the whole decision. Without that the request
            | would read APPROVED over a plan that was never written.
            */
            if ($hidden !== [] && ! $acknowledgeHiddenCohorts) {
                throw new PlanWouldHideCohorts($hidden);
            }

            /*
            | ⛔ **AND THE NAMES TRAVEL OUT TO THE TEACHER'S MESSAGE.** The
            | officer ticking the box is the officer accepting the cost — it is
            | not permission to keep it from the person who owns the groups.
            | Before this the teacher read «وافقت الإدارة» and nothing else, and
            | found out a group had gone dark when a student asked them why it
            | was not showing. FR-013 says the TEACHER is told.
            |
            | ⚠️ AND THIS NUMBER IS MEASURED RATHER THAN PREDICTED, which is the
            | whole reason it is sent from here and not from the ask: at the ask
            | nothing is written, so a count there is a guess about a row that
            | does not exist yet and a decision days away.
            */
            return array_values(array_map(
                static fn (array $cohort): string => $cohort['name'],
                $hidden,
            ));
        });
    }

    /**
     * ⚠️ THE SENTENCE CARRIES THE SHAPE, NOT A LINK TO IT. A teacher reading
     * «تمت الموافقة» on a phone at night needs to know what they now sell; a
     * notification whose whole content is a second trip to the panel is the
     * defect this tree already records for the scheduled report.
     */
    /** @param  list<string>  $hiddenCohorts */
    private function tellTeacher(PlanChangeRequest $request, bool $approve, array $hiddenCohorts = []): void
    {
        $teacher = $request->requester;

        if ($teacher === null) {
            return;
        }

        $shape = PlanShape::describe(
            $request->requested_duration_days,
            $request->requested_session_count,
        ) ?? '—';

        $this->notify->handle(new NotificationRequest(
            recipient: $teacher,
            type: $approve
                ? NotificationType::PlanChangeApproved
                : NotificationType::PlanChangeRejected,
            variables: [
                'plan_title' => (string) ($request->plan->title ?? '—'),
                'shape' => $shape,
                'hidden_cohorts' => self::hiddenSentence($hiddenCohorts),
                'reason' => $request->decision_reason ?? 'بلا ملاحظات.',
            ],
            actionUrl: '/manage/plans',
            workspaceId: (int) $request->workspace_id,
        ));
    }

    /**
     * What the teacher reads about what this decision cost them.
     *
     * ⛔ **NEVER AN EMPTY STRING, AND THAT IS NOT TIDINESS.** `TemplateRenderer`
     * counts a present-but-EMPTY variable as MISSING and throws a permanent
     * delivery failure — so an empty answer here would drop the whole
     * notification, and a teacher whose approval cost them nothing would be told
     * nothing about the approval either. `reason` above already carries the same
     * fallback for the same reason.
     *
     * ⚠️ AND THE QUIET CASE IS SAID OUT LOUD ON PURPOSE. A sentence that
     * appears only when something went wrong is a sentence nobody knows to look
     * for; one that is always there is one a teacher reads.
     *
     * ⚠️ THE COUNTED NOUN GOES THROUGH {@see CountedNoun}. Arabic agrees the
     * noun in five bands, and this is a sentence about somebody's own students.
     *
     * @param  list<string>  $names
     */
    private static function hiddenSentence(array $names): string
    {
        if ($names === []) {
            return 'ولم تخرج أيّ مجموعة من العرض.';
        }

        $counted = CountedNoun::of(count($names), [
            'one' => 'مجموعة واحدة فيها طلاب',
            'two' => 'مجموعتان فيهما طلاب',
            'few' => 'مجموعات فيها طلاب',
            'many' => 'مجموعةً فيها طلاب',
            'other' => 'مجموعة فيها طلاب',
        ]);

        return 'وخرجت من العرض '.$counted.': '.implode('، ', $names)
            .' — من فيها يبقون مكانهم، ولن تظهر لمن يبحث عن مكان.';
    }
}
