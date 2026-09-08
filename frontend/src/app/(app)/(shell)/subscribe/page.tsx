"use client";

import { Suspense, useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Field, SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { dashboardAudience } from "@/lib/dashboard-audience";
import { userMessage } from "@/lib/errors";
import { family } from "@/lib/notifications";
import type { GuardianRelation } from "@/lib/notifications";
import { formatMinorMoney } from "@/lib/labels";
import { SESSION_TYPE_LABELS, planDuration, type Plan } from "@/lib/plans";
import type { CourseDetail } from "@/lib/public-api";
import {
  chosenCohort,
  sessionTypeFor,
  subscribe,
  type SubscriptionMode,
  type SubscriptionOrder,
} from "@/lib/subscribe";

/**
 * The one subscription screen (027 · FR-006 · FR-007 · FR-010).
 *
 * ⚠️ IT ASKS FOR NO ENROLMENT AND NO BALANCE, AND THAT IS THE WHOLE POINT.
 * Every purchase screen in the product built its picker out of something the
 * buyer already owned — `/billing/purchase` from the enrolments, `/plans` from
 * the balances — so a student with neither read «ابدأ بالتسجيل في واحد» and was
 * sent to a catalogue that returned them here. A picker built from what the new
 * buyer does not yet have is a circular lock, and it held the product's entire
 * revenue path.
 *
 * ⚠️ AND THE WHOLE STATE IS IN THE ADDRESS. The course page is public and server
 * rendered; it cannot know who is reading it. So the choice travels as
 * `?course=…&cohort=…` (or `&mode=private`), which is also what lets the shell
 * carry a signed-out visitor through sign-in and put them back here with the
 * group they picked still chosen.
 */
function SubscribeScreen() {
  const params = useSearchParams();
  const courseUuid = params.get("course") ?? "";
  const cohortUuid = params.get("cohort");
  const mode: SubscriptionMode = cohortUuid !== null ? "cohort" : "private";

  const { user } = useAuth();
  /*
   * ⚠️ **`dashboardAudience`، لا `platform_role === "parent"`.** الترجمةُ من
   * قيمةِ الخادمِ إلى «أيُّ تخطيطٍ يخصُّك» مكتوبةٌ مرّةً واحدةً هناك، ومقارنةٌ
   * حرفيّةٌ هنا تهجئةٌ ثانيةٌ تفترقُ عن الشريطِ الجانبيِّ عندَ أوّلِ تعديل.
   */
  const isGuardian = dashboardAudience(user) === "guardian";

  const [course, setCourse] = useState<CourseDetail | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  /** أبناءُ هذا الوصيِّ الذين له عليهم صلاحيّةُ الدفعِ ولهم حسابٌ فعلاً. */
  const [children, setChildren] = useState<GuardianRelation[]>([]);
  const [studentUuid, setStudentUuid] = useState("");
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);

  const [planUuid, setPlanUuid] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [method, setMethod] = useState("bank_transfer");
  const [sending, setSending] = useState(false);
  const [placed, setPlaced] = useState<SubscriptionOrder | null>(null);
  const problemRef = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    if (courseUuid === "") {
      setState("error");
      setProblem("لا كورس في هذا الرابط. افتحْ صفحة الكورس واضغطْ زرّ الاشتراك.");

      return;
    }

    setState("loading");

    try {
      const [detail, offers] = await Promise.all([
        subscribe.course(courseUuid),
        subscribe.plans(courseUuid, sessionTypeFor(mode)),
      ]);

      setCourse(detail.data);
      setPlans(offers.data);

      if (isGuardian) {
        /*
         * ⚠️ **المرشِّحانِ كلاهما مطلوبٌ، وكلٌّ منهما يحرسُ رفضاً مختلفاً.**
         * `student_uuid` غائبٌ عن ابنٍ أضافَه الوصيُّ بالاسمِ ولم يفتحْ حساباً
         * بعد — لا حسابَ يُسجَّلُ فيه أصلاً؛ و`payments` هي الصلاحيّةُ عينُها
         * التي يسألُها الخادمُ في `PurchaseBeneficiary`. ومنتقٍ يعرضُ من يرفضُه
         * الخادمُ هو عطبُ «تهجئتَينِ لسؤالٍ واحد» الذي دفعَ ثمنَه
         * `ListLeaderboardScopes`.
         */
        const relations = await family.list().catch(() => ({ data: [] }));

        setChildren(
          relations.data.filter(
            (relation) =>
              relation.status === "active"
              && typeof relation.student_uuid === "string"
              && relation.permissions.some((permission) => permission.key === "payments"),
          ),
        );
      }

      setState("ready");
    } catch (error: unknown) {
      setProblem(userMessage(error));
      setState("error");
    }
  }, [courseUuid, mode, isGuardian]);

  useEffect(() => {
    void load();
  }, [load]);

  /*
   * ⚠️ THE REFUSAL IS AT THE TOP OF THE PAGE AND THE BUTTON IS AT THE BOTTOM.
   * Reported from a real purchase (2026-09-06): the request was refused, the
   * Alert below rendered exactly as designed — and the buyer, standing on the
   * send button, saw NOTHING happen. A message nobody can see is a swallowed
   * error wearing markup, and they press send again over the same cause.
   *
   * Scrolled rather than toasted: the sentence has to stay on screen beside the
   * choice it is about (FR-005أ), and a toast that fades takes the reason with
   * it. `role="alert"` on the Alert is what says it to a screen reader.
   */
  useEffect(() => {
    if (problem === null || state !== "ready" || placed !== null) return;

    problemRef.current?.scrollIntoView({ behavior: "smooth", block: "center" });
  }, [problem, state, placed]);

  const submit = async () => {
    if (planUuid === "" || receipt === null) return;
    if (isGuardian && studentUuid === "") return;

    setSending(true);
    setProblem(null);

    try {
      /*
       * ⚠️ THE ORDER FIRST, THEN THE RECEIPT ONTO IT. There is no combined
       * endpoint and there must not be: `POST /orders/{uuid}/receipt` is the one
       * path that records the method, the IP and the user agent together.
       */
      const { data: order } = await subscribe.create({
        plan_uuid: planUuid,
        mode,
        ...(cohortUuid === null ? {} : { cohort_uuid: cohortUuid }),
        // الطالبُ يُرسَلُ من الوصيِّ وحدَه؛ ومن يشتري لنفسِه لا يُسمّي أحداً،
        // فالخادمُ يقرأُ صاحبَ الجلسةِ ولا يكتبُ `granted_by` أصلاً.
        ...(isGuardian ? { student_uuid: studentUuid } : {}),
      });

      await subscribe.uploadReceipt(order.uuid, receipt, method);

      setPlaced(order);
    } catch (error: unknown) {
      setProblem(userMessage(error));
    } finally {
      setSending(false);
    }
  };

  if (state === "loading") return <RowsSkeleton />;

  if (state === "error") {
    return <ErrorState description={problem ?? undefined} onRetry={() => void load()} />;
  }

  if (placed !== null) {
    return (
      <div className="space-y-6">
        <Alert tone="success" title="وصل طلبك">
          {/* FR-010, said out loud: nothing is owed to the buyer until an officer
              has seen the transfer. A screen that implied otherwise would have
              them turning up to a class they cannot enter. */}
          سنراجع إيصالك، ويبدأ اشتراكك عند اعتماد الدفعة — لا قبله. سيصلك إشعار
          بمواعيد {mode === "cohort" ? "مجموعتك" : "حصصك الخاصة"} وبالرابط الذي تدخل منه.
        </Alert>

        <p className="text-sm text-ink-muted">
          تتابع حالة الطلب من{" "}
          <Link href="/orders" className="font-bold underline">
            صفحة الطلبات
          </Link>
          .
        </p>
      </div>
    );
  }

  const cohort = chosenCohort(course, cohortUuid);
  const teacher = course?.teacher?.name ?? null;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">
          {mode === "cohort" ? "الاشتراك في مجموعة" : "الاشتراك بحصص خاصة"}
        </h1>
        <p className="mt-1 text-sm text-ink-muted">
          اختَرِ الباقة، وارفعْ إيصال التحويل، وأرسِلْ — خطوة واحدة.
        </p>
      </header>

      {problem !== null && (
        /*
         * ⚠️ THE CHOICE IS NOT LOST WITH THE REFUSAL (FR-005أ). Whatever stopped
         * the request — a guardian's consent still outstanding, a group that
         * filled, a plan withdrawn — the address still holds the course and the
         * group, so the screen the reader is looking at IS where they resume. A
         * refusal that bounced them elsewhere would make them start again, which
         * is the complication this feature exists to remove.
         */
        <div ref={problemRef}>
          <Alert tone="danger" title="لم يُرسل الطلب">
            <p>{problem}</p>
            <p className="mt-2">
              اختيارك محفوظ في هذه الصفحة — عالِجِ السبب أعلاه ثم أرسِلْ من هنا.
            </p>
          </Alert>
        </div>
      )}

      {/*
        ⚠️ **وليُّ الأمرِ يشتري لابنٍ مُسمّى، لا لنفسِه** (بلاغُ ٢٠٢٦-٠٩-٠٨). كانت
        هذه الشاشةُ ترسلُ صاحبَ الجلسةِ طالباً، فوليُّ أمرٍ ضغطَ «اشترك» صارَ هو
        الطالبَ: تسجيلٌ وعضويّةُ مجموعةٍ باسمِه، وابنُه بلا شيء.

        والمنتقي **أوّلَ ما يُقرَأُ على الصفحة**: لمن هذا الاشتراكُ سؤالٌ يسبقُ
        «أيُّ باقة»، ومنتقٍ تحتَ الباقةِ يُملَأُ بعدَ أن يكونَ القارئُ قد اختارَ
        على افتراضٍ آخر. والخادمُ يرفضُ الفارغَ على كلِّ حال — هذا يقولُ السببَ
        قبلَ الرفضِ لا بعدَه.
      */}
      {isGuardian &&
        (children.length === 0 ? (
          <Card>
            <EmptyState
              title="لا يوجد ابن يمكنك الاشتراك له"
              description="الاشتراك يكون باسم طالب له حساب على المنصّة، ولك عليه صلاحيّة «المدفوعات والمستحقّات». أضِف الطالب أو عدّل صلاحيّاتك من صفحة المرتبطين."
              // التسميةُ تسميةُ الشريطِ الجانبيِّ نفسُها — اسمانِ لشاشةٍ واحدةٍ
              // يجعلانِ القارئَ يبحثُ عن بندٍ لا وجودَ له.
              action={
                <Button href="/family" variant="secondary">
                  المرتبطون
                </Button>
              }
            />
          </Card>
        ) : (
          <Card>
            <SelectField
              id="student"
              label="لمن هذا الاشتراك؟"
              value={studentUuid}
              onChange={setStudentUuid}
              placeholder="اختر الطالب…"
              options={children.map((relation) => ({
                // مُرشَّحٌ فوقُ على وجودِ المعرِّف، فالحارسُ هنا للنوعِ لا للحالة.
                value: relation.student_uuid ?? "",
                label: relation.student_name,
              }))}
              hint="الاشتراك والحصص والمجموعة تُكتَب باسم الطالب، والدفع باسمك."
              required
            />
          </Card>
        ))}

      {/* What is being bought, shown back before any money is named (FR-006). */}
      <Card>
        <h2 className="text-sm font-bold text-ink">ما ستشترك فيه</h2>
        <dl className="mt-3 space-y-2 text-sm">
          <div className="flex gap-2">
            <dt className="text-ink-muted">الكورس:</dt>
            <dd className="font-medium text-ink">{course?.title ?? "—"}</dd>
          </div>
          {teacher !== null && (
            <div className="flex gap-2">
              <dt className="text-ink-muted">المدرّس:</dt>
              <dd className="font-medium text-ink">{teacher}</dd>
            </div>
          )}
          <div className="flex gap-2">
            <dt className="text-ink-muted">الاختيار:</dt>
            <dd className="font-medium text-ink">
              {mode === "private" ? "حصص خاصّة" : (cohort?.name ?? "مجموعة")}
            </dd>
          </div>
          {cohort !== null && cohort.schedule.length > 0 && (
            <div className="flex gap-2">
              <dt className="text-ink-muted">المواعيد:</dt>
              <dd className="font-medium text-ink">{cohort.schedule.join(" · ")}</dd>
            </div>
          )}
        </dl>
      </Card>

      <Card>
        <h2 className="text-sm font-bold text-ink">اختَرِ الباقة</h2>

        {plans.length === 0 ? (
          <div className="mt-3">
            <EmptyState
              title="لا باقات متاحة الآن"
              description="لم يفتح المدرّس باقة بهذه المدّة بعد. تابع صفحته لتعرف حين يفتحها."
            />
          </div>
        ) : (
          <ul className="mt-3 space-y-2">
            {plans.map((plan) => (
              <li key={plan.uuid}>
                <label className="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line p-4 has-[:checked]:border-primary">
                  <span className="flex items-center gap-3">
                    <input
                      type="radio"
                      name="plan"
                      value={plan.uuid}
                      checked={planUuid === plan.uuid}
                      onChange={() => setPlanUuid(plan.uuid)}
                      className="size-4 accent-[var(--color-primary)]"
                    />
                    <span>
                      <span className="block text-sm font-medium text-ink">{plan.title}</span>
                      <span className="block text-xs text-ink-muted">
                        {planDuration(plan.duration_days)} ·{" "}
                        {SESSION_TYPE_LABELS[plan.session_type]}
                      </span>
                    </span>
                  </span>
                  {/* ⚠️ ONE TOTAL, NO BREAKDOWN (FR-009 · FR-034). A split into
                      parts is two numbers that solve for the teacher's settlement
                      rate across two package sizes. */}
                  <span className="text-sm font-semibold text-ink">
                    {plan.price_minor === null
                      ? "—"
                      : formatMinorMoney(plan.price_minor, plan.currency)}
                  </span>
                </label>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <h2 className="text-sm font-bold text-ink">إيصال التحويل</h2>
        <p className="mt-1 text-xs text-ink-muted">
          حوِّلْ قيمة الباقة إلى حساب المنصّة، ثم ارفعْ صورة التحويل أو ملف PDF.
        </p>

        <div className="mt-3 space-y-3">
          {/*
            ⚠️ `SelectField`, NOT `Field` ROUND A BARE `Select`. `Select` is the
            chevron and its lane and nothing else — it takes its whole appearance
            from the caller's `className`, so with none it renders a
            browser-default control: no border, no background, smaller than every
            field beside it, and an option list the browser paints white under
            the page's own light text. Reported on the sibling screen, found here
            by the scan in `src/components/ui/select-styling.test.ts`.
          */}
          <SelectField
            id="subscribe-method"
            label="طريقة الدفع"
            value={method}
            onChange={setMethod}
            options={[
              { value: "bank_transfer", label: "تحويل بنكي" },
              { value: "mobile_wallet", label: "محفظة إلكترونية" },
            ]}
          />

          <Field
            id="subscribe-receipt"
            label="صورة الإيصال"
            hint="صورة أو PDF، حتى ١٠ ميغابايت."
            required
          >
            <input
              id="subscribe-receipt"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              onChange={(event) => setReceipt(event.target.files?.[0] ?? null)}
              className="block w-full text-sm text-ink file:me-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white"
            />
          </Field>
        </div>
      </Card>

      <Button
        type="button"
        onClick={() => void submit()}
        disabled={planUuid === "" || receipt === null || sending || (isGuardian && studentUuid === "")}
        loading={sending}
        loadingLabel="جارٍ الإرسال…"
        fullWidth
      >
        أرسِلِ الطلب
      </Button>

      <p className="text-xs text-ink-muted">
        لا يبدأ اشتراكك ولا يُفتح لك شيء قبل أن يعتمد الموظّف الدفعة.
      </p>
    </div>
  );
}

export default function SubscribePage() {
  // `useSearchParams()` needs a boundary, and without one the build fails for
  // the whole route tree rather than for this page.
  return (
    <Suspense fallback={<RowsSkeleton />}>
      <SubscribeScreen />
    </Suspense>
  );
}
