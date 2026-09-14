<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\ReceiptApproved;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A human accepts a transfer they read the receipt for.
 *
 * ⚠️ THE DECISION IS ONE ATOMIC CONDITIONAL UPDATE, the same idiom the webhook
 * capture uses. `isPending()` then `update()` is a check and a write with a
 * window between them, and two operators clicking "اعتماد" on the same order in
 * that window both passed the check — two `PaymentApproved` events, two
 * enrolments, and on a credit order two mints of the same money. The loser here
 * affects zero rows and is told the decision was already taken.
 *
 * ⚠️ AND IT WRITES `captured_order_id`, which the manual path never did. That
 * column is the engine-level "one capture per order" invariant introduced for the
 * gateway; leaving the manual path out of it left the hole open on the only path
 * that was actually in use.
 */
class ApproveOrder extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly CohortDirectory $cohorts,
    ) {}

    /**
     * @param  string|null  $cohortUuid  the group the officer picked, on an order
     *                                   for a course that runs in groups
     *                                   (٠٣٤ · FR-016). Required **by this
     *                                   Action** rather than by the screen:
     *                                   approval has two doors — the panel button
     *                                   and `POST /orders/{orderUuid}/approve` —
     *                                   and a guard on the screen guards what is
     *                                   pressed while leaving what is called wide
     *                                   open (FR-016ب).
     */
    public function handle(
        Order $order,
        User $approver,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $cohortUuid = null,
    ): Order {
        return DB::transaction(function () use ($order, $approver, $ipAddress, $userAgent, $cohortUuid): Order {
            $cohort = $this->resolveCohortToClaim($order, $cohortUuid);

            /*
            | ⚠️ `withoutWorkspaceScope()` — THE THIRD LAYER OF ONE DEFECT.
            |
            | This claim is a conditional UPDATE through the model, so the global
            | scope ANDs the CURRENT workspace onto it. A platform officer's
            | context falls back to `users.last_workspace_id` like anybody
            | else's, so an officer who also owns a workspace matched ZERO rows
            | on every order outside it — and was told «Only pending orders can
            | be approved» about an order that was pending. The other two layers
            | were route-model binding (404) and `OrderPolicy` (403); all three
            | had to move, and each one hid the next.
            |
            | Safe because the row is already authorised: `OrderPolicy` ran above
            | this call and asks the workspace question itself on every
            | non-platform branch. Measured 2026-09-03 (024).
            */
            $claimed = Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($order->getKey())
                ->awaitingDecision()
                ->update([
                    'status' => 'approved',
                    'approved_by' => $approver->getKey(),
                    'approved_at' => now(),
                ]);

            if ($claimed === 0) {
                // ⚠️ ARABIC, LIKE EVERY OTHER SENTENCE A HUMAN READS IN THIS
                // PRODUCT. This is not an internal invariant: it is what the
                // SECOND officer sees when two press «اعتمد» at the same
                // instant, and spec 027 puts that button on a queue where a
                // simultaneous press is ordinary rather than exotic.
                throw new DomainException('اتُّخِذ القرار على هذا الطلب بالفعل.');
            }

            /*
            | ⛔ **المقعدُ يُطالَبُ به قبلَ قبضِ المال** (FR-024أ).
            |
            | إعادةُ القياسِ وحدَها **قراءةٌ لا مُطالَبة**: موظَّفانِ يعتمدانِ
            | طلبَينِ على آخرِ مقعدٍ يمرّانِ كلاهما منها ويقبضانِ كلاهما، وأحدُ
            | الطالبَينِ يبقى بلا مجموعة — أي استردادٌ بالتعريف. والجملةُ أدناه
            | هي الفحصُ والمُطالَبةُ معاً، فيربحُ واحدٌ ويُرَدُّ الآخرُ **والطلبُ
            | ما زالَ معلَّقاً ولم يُقبَضْ منه شيء**، وهو ما يجعلُ `SC-009`
            | («صفرُ استردادٍ سببُه الامتلاء») قابلاً للتحقّقِ أصلاً.
            |
            | ⚠️ **وداخلَ معاملةِ الاعتمادِ نفسِها**: أيُّ رميةٍ تحتَها تُرجِعُ
            | المقعدَ بلا كتابةٍ تعويضيّة.
            |
            | ⚠️ **ومَن طالبَ هنا يقولُ ذلكَ للكاتبِ لاحقاً** (`T021أ`): العضويّةُ
            | تُكتَبُ بمهمّةٍ مُصطفّةٍ بعدَ التثبيت، و`CohortMembershipWriter::open()`
            | يُطالِبُ بمقعدِه بنفسِه — فبلا الرايةِ يتسرّبُ مقعدٌ في كلِّ اعتماد،
            | **وقد تُرفَضُ الزيادةُ الثانيةُ بـ«مكتملة» بعدَ أن قُبِضَ المال**.
            */
            if ($cohort !== null && ! $this->cohorts->claimSeat((int) $cohort['id'])) {
                throw new DomainException(
                    'امتلأت المجموعة قبل اعتماد هذا الطلب. لم يُقبض أيّ مبلغ والطلب ما زال معلّقاً — '
                    .'تواصل مع الطالب لاختيار مجموعة أخرى.'
                );
            }

            $this->mintTransaction($order);

            $order->refresh();

            if ($cohort !== null && $order->kind === OrderKind::Course) {
                $this->recordChoice($order, $cohort);
            }

            event(new PaymentApproved($order));

            ReceiptApproved::dispatch($order, $approver);

            $this->logActivity('approved', $order, [
                'amount_minor' => $order->amount_minor,
                // FR-024 — a financial decision records the terminal it was taken
                // from, not only the account. An operator's session on a machine
                // that is not theirs is the case the address exists for.
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ]);

            return $order;
        });
    }

    /**
     * The re-check between ordering and approving (027 · FR-026).
     *
     * ⚠️ IN THE ACTION, NOT IN THE FILAMENT SCREEN. Approval has two doors — the
     * officer's button and `POST /orders/{orderUuid}/approve` — and a guard on
     * the screen guards what is pressed while leaving what is called wide open.
     *
     * ⚠️ AND NOT IN `ActivateSubscription`. That listener is queued and runs
     * after commit, so refusing there produces literally the state FR-026 exists
     * to prevent: an approved order, money taken, and a student in no group.
     *
     * ⚠️ AND IT THROWS BEFORE THE CLAIM, so the transaction rolls back with
     * nothing in it — no status change, no payment transaction, no activity row.
     * The order is still `pending` and the officer reads a sentence naming the
     * cause.
     *
     * ⚠️ THE `PaymentCaptured` DOOR DOES NOT PASS THROUGH HERE. `ActivateSubscription`
     * is wired to both events; today every subscription order is `manual` so a
     * gateway capture cannot reach it, but the day one can, this check will not
     * have run.
     */
    /**
     * المجموعةُ التي يجبُ أن يُطالَبَ بمقعدٍ فيها قبلَ قبضِ المال، أو `null`.
     *
     * ⚠️ **بابانِ في دالّةٍ واحدةٍ عمداً** (FR-024أ). طلبُ الكورسِ يحملُ اختيارَ
     * الموظَّف، وطلبُ الاشتراكِ يحملُ اختيارَ الطالبِ في لقطتِه — والسؤالُ
     * المطروحُ عليهما واحد: «هل يُؤخَذُ مقعدٌ هنا، وأيّ مقعد؟». جوابانِ منفصلانِ
     * كانا سيُطالِبانِ بمقعدَينِ أو ينسيانِ أحدَهما.
     *
     * @return array<string, mixed>|null
     */
    private function resolveCohortToClaim(Order $order, ?string $cohortUuid): ?array
    {
        if ($order->kind === OrderKind::Course) {
            return $this->resolveCourseOrderCohort($order, $cohortUuid);
        }

        if ($order->kind !== OrderKind::Subscription) {
            return null;
        }

        $intent = SubscriptionIntent::fromOrder($order);

        if ($intent === null || ! $intent->isCohort() || $intent->cohortUuid === null) {
            return null;
        }

        $cohort = $this->describe($intent->cohortUuid);

        if ($cohort === null) {
            throw new DomainException('المجموعة المطلوبة لم تعد موجودة. تواصل مع الطالب لاختيار مجموعة أخرى.');
        }

        if ($cohort['course_status'] !== 'published') {
            throw new DomainException('كورس هذه المجموعة لم يعد منشوراً. أعد نشره أو تواصل مع الطالب قبل الاعتماد.');
        }

        $student = $order->user;

        /*
        | ⚠️ MEMBERSHIP IS ASKED FIRST, AND ASKING JOINABILITY FIRST REFUSES EVERY
        | RENEWAL. A renewing student's group is full OF THEM AND THEIR
        | CLASSMATES, so `isJoinable()` is false for exactly the person US4·4 and
        | FR-028 promise must not be asked to choose a group again — and their
        | paid, approved order would sit `pending` for ever under «هذه المجموعة لم
        | تعد متاحة». `PurchaseSubscription::resolveCohort()` already orders the
        | two the same way; this is the same question, not a second one.
        */
        $current = $this->cohorts->openMembershipCohortId($student, (int) $cohort['course_id']);

        if ($current !== null && $current !== (int) $cohort['id']) {
            /*
            | They joined a different group between ordering and approval. Neither
            | of the two obvious answers is acceptable: the activation listener
            | would throw AFTER the money committed, and moving them silently would
            | take them out of the group they are in with nobody deciding it — a
            | transfer bought for the price of the cheapest plan, filed in the log
            | as a join. So the APPROVAL is refused, while it can still be refused.
            */
            throw new DomainException('الطالب مسجّل في مجموعة أخرى من هذا الكورس. يحتاج طلب نقل قبل اعتماد هذا الاشتراك.');
        }

        if ($current === (int) $cohort['id']) {
            /*
            | تجديدٌ داخلَ مجموعتِه نفسِها: لا مقعدَ يُبحَثُ عنه، **ولا
            | مقعدَ يُطالَبُ به** — فلذلكَ تردُّ `null` لا الصفّ. و
            | `ActivateSubscription` يتخطّى الكتابةَ بالشرطِ نفسِه وبالترتيبِ
            | نفسِه، فلا يختلفان.
            */
            return null;
        }

        if (! $this->cohorts->isJoinable((int) $cohort['id'])) {
            throw new DomainException('لم تعد هذه المجموعة متاحة للانضمام. تواصل مع الطالب لاختيار مجموعة أخرى.');
        }

        return $cohort;
    }

    /**
     * الكورسُ الذي يُدرَّسُ في مجموعات: الموظَّفُ يختارُ لحظةَ الاعتماد (FR-016).
     *
     * ⚠️ **وفي كلِّ ما عدا ذلكَ يمضي الاعتمادُ بلا مجموعة**: كورسٌ لا مجموعةَ
     * فيه إطلاقاً، أو امتلأَت كلُّ مجموعاتِه، أو أُرشِفَت. **اشتراطُ مجموعةٍ
     * حيثُ لا توجدُ واحدةٌ يجعلُ طلباً مدفوعاً لا يُعتمَدُ أبداً** — وهو العطبُ
     * نفسُه الذي تمنعُه FR-015، منقولاً خطوةً إلى الوراء.
     *
     * ⚠️ **والسؤالُ «صالحةٌ للإسناد» لا «صالحةٌ للانضمام»** (FR-030): المغلَقةُ
     * غيرُ المكتمِلةِ وجهةٌ مشروعةٌ للإدارة، و«صالحةٌ للانضمام» كانت سترفضُها
     * فيُمضي الاعتمادُ بلا مجموعةٍ عن كورسٍ له واحدة.
     *
     * @return array<string, mixed>|null
     */
    private function resolveCourseOrderCohort(Order $order, ?string $cohortUuid): ?array
    {
        $courseId = $order->course_id;

        if ($courseId === null) {
            return null;
        }

        /*
        | الطالبُ في مجموعةٍ من هذا الكورسِ سلفاً: لا مقعدَ يُطالَبُ به،
        | ولا إسنادَ يُكتَب. ونقلُه قرارٌ ثانٍ يُتَّخَذُ من شاشةِ الإسناد، لا
        | أثرٌ جانبيٌّ لاعتمادِ طلبٍ اشتراه. **وقبلَ طلبِ الاختيارِ لا بعدَه**:
        | وإلّا لمُنِعَ المجدِّدُ من الاعتمادِ حتّى يُختارَ له مجموعةٌ هو فيها.
        */
        if ($this->cohorts->openMembershipCohortId($order->user, (int) $courseId) !== null) {
            return null;
        }

        /*
        | ⛔ **الاختيارُ المُسمَّى يُفحَصُ دائماً، ولا يُبتلَعُ بسؤالِ «هل بقيَت
        | وجهةٌ؟».** كُتِبَ هذا أوّلاً بالترتيبِ المعكوس — «إن لم تبقَ مجموعةٌ
        | صالحةٌ فامضِ بلا مجموعة» **فوقَ** قراءةِ ما اختارَه الموظَّف — وقِيسَ أنّ
        | ذلكَ يُمضي الاعتمادَ **بلا مجموعةٍ وقد قُبِضَ المال** حينَ تمتلئُ
        | المجموعةُ التي اختارَها بينَ فتحِه الشاشةَ وضغطِه الزرّ: أي بالضبطِ
        | الحالُ التي كُتِبَت `FR-024أ` لأجلِها، مقلوبةً.
        */
        if ($cohortUuid === null || $cohortUuid === '') {
            /*
            | لم يُسمَّ شيء: عندئذٍ وحدَه يُسأَلُ «هل للكورسِ وجهةٌ صالحةٌ أصلاً؟».
            | و**لا** يُشترَطُ حينَ لا توجد (FR-016): كورسٌ بلا مجموعاتٍ أو
            | امتلأَت كلُّها يُعتمَدُ بلا مجموعةٍ ويعملُ FR-015 — واشتراطُ مجموعةٍ
            | حيثُ لا توجدُ واحدةٌ يجعلُ طلباً مدفوعاً لا يُعتمَدُ أبداً.
            */
            if ($this->cohorts->assignableCohortsExist((int) $courseId)) {
                throw new DomainException('اختر المجموعة قبل اعتماد الطلب — هذا الكورس يُدرَّس في مجموعات.');
            }

            return null;
        }

        $cohort = $this->describe($cohortUuid);

        /*
        | ⚠️ **الكورسُ جزءٌ من السؤالِ لا مجاملة.** بلا هذا الشرطِ يُسمّي
        | المُنادي أيَّ معرِّفِ مجموعةٍ على المنصّةِ فيُوضَعُ الطالبُ في غرفةٍ لا
        | يملكُ فيها تسجيلاً — والبابُ البرمجيُّ يُكتَبُ كما يُنقَر.
        */
        if ($cohort === null || (int) $cohort['course_id'] !== (int) $courseId) {
            throw new DomainException('المجموعة المختارة ليست من مجموعات هذا الكورس.');
        }

        /*
        | ⚠️ **قراءةٌ من أجلِ الجملة، والمُطالَبةُ هي الحارس.** هذه تُعطي
        | الموظَّفَ سبباً مسمّىً في الحالةِ العاديّة (امتلأَت أمسِ، أو أُرشِفَت)؛
        | أمّا موظَّفانِ يضغطانِ في اللحظةِ نفسِها فيمرّانِ كلاهما من هنا —
        | ويُرَدُّ أحدُهما بالجملةِ الشرطيّةِ في `handle()` **قبلَ قبضِ المال**.
        | واحدةٌ بلا الأخرى: إمّا رسالةٌ لا تصفُ شيئاً، أو سباقٌ بلا حارس.
        */
        if (! $this->cohorts->isAssignable((int) $cohort['id'])) {
            throw new DomainException('لم تعد هذه المجموعة وجهةً ممكنة — اختر مجموعة أخرى قبل الاعتماد.');
        }

        return $cohort;
    }

    /**
     * وصفُ المجموعةِ ومعه **معرِّفُها العلنيُّ**.
     *
     * ⚠️ `describeGroupCohort()` لا يردُّ `uuid` ولا يحتاجُه قرّاؤُه القدامى —
     * وهذا المسارُ يحفظُ الاختيارَ على الطلبِ، وما يُحفَظُ هو المعرِّفُ
     * العلنيُّ لا الرقمُ الداخليُّ (`HasUuid`: الحمولاتُ تحملُ `uuid` دائماً).
     *
     * @return array<string, mixed>|null
     */
    private function describe(string $uuid): ?array
    {
        $cohort = $this->cohorts->describeGroupCohort($uuid);

        return $cohort === null ? null : [...$cohort, 'uuid' => $uuid];
    }

    /**
     * ما اختارَه الموظَّفُ، محفوظاً على الطلبِ نفسِه (FR-016أ).
     *
     * ⚠️ **عبرَ النموذجِ لا بتحديثٍ جماعيّ.** `orders.metadata` عمودٌ مُنمَّطٌ
     * `array`، وجملةُ `update()` الجماعيّةُ لا تسترجِعُ نموذجاً فلا يسري عليها
     * تنميطٌ — قاعدةُ `LedgerEntry` نفسُها، والأثرُ هنا حقلٌ يُقرَأُ فارغاً
     * إلى الأبد.
     *
     * ⚠️ **والاسمُ يُحفَظُ معَ المعرِّف**، على سابقةِ `SubscriptionIntent`: صفٌ
     * يجبُ أن يبقى مقروءاً بعدَ أن تُؤرشَفَ المجموعةُ أو يُغَيَّرَ اسمُها.
     *
     * @param  array<string, mixed>  $cohort
     */
    private function recordChoice(Order $order, array $cohort): void
    {
        $order->forceFill([
            'metadata' => [
                ...($order->metadata ?? []),
                'assigned_cohort_uuid' => $cohort['uuid'],
                'assigned_cohort_name' => $cohort['name'],
            ],
        ])->save();
    }

    /**
     * The captured transaction this approval stands for.
     *
     * ⚠️ A GATEWAY PAYMENT MAY HAVE ALREADY CAPTURED THIS ORDER. Nothing moves
     * `orders.status` when a webhook captures — enrolment and minting hang off the
     * event, not off the column — so the order still reads `pending` and an
     * operator can reach this screen for money that has already arrived. The
     * unique index on `captured_order_id` is what makes the second capture
     * impossible; catching it here is what turns a 500 on a money path into a
     * sentence the operator can act on.
     */
    private function mintTransaction(Order $order): void
    {
        $transaction = new PaymentTransaction([
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->getKey(),
            'provider' => $order->provider,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => PaymentStatus::Captured,
            'method' => $this->methodOf($order),
            'reference' => 'manual-approval-'.$order->getKey(),
            'settled_at' => now(),
        ]);

        // ONE insert carrying the claim, never an insert followed by an update:
        // the second form commits a captured transaction and only then asks
        // whether it was allowed to, so a failure leaves the row behind.
        $transaction->forceFill(['captured_order_id' => $order->getKey()]);

        try {
            $transaction->save();
        } catch (QueryException $e) {
            throw new DomainException('هذا الطلب سُدِّد بالفعل عبر بوابة الدفع.', previous: $e);
        }
    }

    /**
     * What the payer said they did, defaulting to a wire.
     *
     * Read from the order's metadata rather than asked again at approval: the
     * payer knows and the approver is guessing, and a guess written into the
     * column an operator reconciles a bank statement by is worse than the
     * conservative default.
     */
    private function methodOf(Order $order): PaymentMethod
    {
        $stored = $order->metadata['method'] ?? null;

        return (is_string($stored) ? PaymentMethod::tryFrom($stored) : null) ?? PaymentMethod::BankTransfer;
    }
}
