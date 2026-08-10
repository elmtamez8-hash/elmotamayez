<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\RecordTermsConsent;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\StudentCreditAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Where an account and a balance come from — lazily, and never twice.
 *
 * Nothing creates an account when a student signs up. A student with no account
 * has a balance of zero, which is the correct answer and needs no row to say it
 * (US1/1). The row appears the first time credits move.
 *
 * Both getters absorb the concurrent-create race by catching the unique
 * violation and reading again, explicitly, in the shape of BookSeat — not by
 * trusting `firstOrCreate` to do it, which is a behaviour of a framework version
 * rather than a decision this code made.
 */
class CreditAccounts
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly ConsentRegistry $consent,
    ) {}

    public function accountFor(User $student): StudentCreditAccount
    {
        $existing = StudentCreditAccount::query()->where('user_id', $student->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return StudentCreditAccount::query()->create(['user_id' => $student->getKey()]);
        } catch (UniqueConstraintViolationException) {
            // Someone else created it between the read and the write. The unique
            // index on `user_id` is what makes "one account per person" true;
            // this is only how we find the one that won.
            return StudentCreditAccount::query()->where('user_id', $student->getKey())->firstOrFail();
        }
    }

    /**
     * The balance for one student in one course, created on first use.
     *
     * `workspace_id` is copied from the COURSE, explicitly, never left to the
     * trait's auto-fill: the charge runs in a queued listener where
     * WorkspaceContext::id() is null, so auto-fill would write the first
     * production row with an empty tenant key and nothing would notice until a
     * teacher's panel came back empty.
     *
     * ⚠️ AND THE OPENING CEILING IS SET HERE, which is the second half of Q-9's
     * "zero without a recorded consent, ١ after one". The other half is
     * {@see RecordTermsConsent}, which grants it to
     * the balances that already exist when the person signs; this is the one that
     * catches every balance opened AFTERWARDS — a student enrolling with a second
     * teacher next month. Without it their new balance would sit at zero until
     * three on-time payments earned a raise from nothing.
     *
     * It does not go through `SetCreditLimit` because it is not a change: there is
     * no previous value to audit, and no standing to announce a transition in — the
     * row is being born. The gate and the cap that Action enforces are both still
     * honoured, the first by the consent read below and the second inside
     * `initialLimitFor()`.
     */
    public function balanceFor(User $student, Course $course): CreditBalance
    {
        $account = $this->accountFor($student);

        $attributes = [
            'student_credit_account_id' => $account->getKey(),
            'course_id' => $course->getKey(),
        ];

        $existing = CreditBalance::query()->withoutWorkspaceScope()->where($attributes)->first();

        if ($existing !== null) {
            return $existing;
        }

        // The cadence and the mode are read from it, so no workspace means no
        // ceiling — the same answer WithholdingReader gives a balance whose
        // tenant cannot be resolved.
        $workspace = $course->workspace;

        try {
            return CreditBalance::query()->create($attributes + [
                'student_user_id' => $student->getKey(),
                'workspace_id' => $course->workspace_id,
                'credit_limit_credits' => $workspace !== null
                    && $this->consent->has($student, ConsentDocument::DeferredPaymentTerms)
                        ? $this->settings->initialLimitFor($workspace)
                        : 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return CreditBalance::query()->withoutWorkspaceScope()->where($attributes)->firstOrFail();
        }
    }

    /**
     * Every balance this student holds, across every teacher.
     *
     * The one sanctioned bypass of the workspace scope on this model, and it is
     * filtered by the account explicitly rather than left open: a student's
     * account spans workspaces by design, so scoping the read would return
     * nothing and block the whole page. Precedent: EloquentEnrollmentDirectory.
     *
     * @return Collection<int, CreditBalance>
     */
    public function balancesFor(User $student): Collection
    {
        $account = StudentCreditAccount::query()->where('user_id', $student->getKey())->first();

        if ($account === null) {
            return CreditBalance::query()->whereRaw('1 = 0')->get();
        }

        return CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('student_credit_account_id', $account->getKey())
            ->get();
    }
}
