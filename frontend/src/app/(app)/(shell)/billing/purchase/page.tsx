"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { CardGridSkeleton, RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { dashboardAudience } from "@/lib/dashboard-audience";
import { errorMessage } from "@/lib/api";
import {
  billing,
  formatCredits,
  type CreditPackageOffer,
  type PurchasableCourse,
  type PurchaseBeneficiary,
} from "@/lib/billing";
import { formatMinorMoney } from "@/lib/labels";

/**
 * Buying credits — for yourself, or for a child you are the guardian of.
 *
 * ⚠️ ONE TOTAL PER PACKAGE AND NO BREAKDOWN — not a design preference. The total
 * is the teacher's approved settlement rate plus two platform constants, so a
 * reader shown the parts could solve for the constants and read every other
 * teacher's pay off any published total (FR-021ج).
 *
 * ⚠️ NOTHING IS CREDITED HERE (FR-018 of spec 006). Pressing a package creates a
 * PENDING ORDER; the credits appear when the platform approves the payment. So
 * the screen hands off to the receipt flow that already exists rather than
 * congratulating anyone.
 *
 * ⚠️ AND NOT ONE OPTION ON THIS SCREEN IS FILTERED IN TYPESCRIPT (031 · FR-018).
 * Both pickers read server doors that ask the SAME question the purchase is
 * proved against — `/billing/beneficiaries` is `childrenOf(caller, payments)` and
 * `/billing/purchasable-courses` is the plural of `isPartyTo`. The screen this
 * replaces built its course list from `/enrollments`, which covers ONE of that
 * predicate's three arms: a child enrolled in one course at a teacher may have
 * credits bought on any of that teacher's courses, and every one of those was
 * silently missing from the picker.
 */

/** The address carries the whole choice, so a reload and a back button both work. */
function useChoice() {
  const params = useSearchParams();

  return {
    course: params.get("course"),
    student: params.get("student"),
  };
}

function hrefFor(course: string | null, student: string | null): string {
  const query = new URLSearchParams();

  if (student !== null) query.set("student", student);
  if (course !== null) query.set("course", course);

  return `/billing/purchase${query.size === 0 ? "" : `?${query.toString()}`}`;
}

/**
 * Who is this for? — asked only of a guardian, and only from the server's list.
 *
 * A guardian account is not a learner account: `dashboardAudience` reads `parent`
 * as a guardian and hides every student screen, so a purchase in their own name
 * is one they could never spend. The server refuses it for the same reason, with
 * «اختر الطالب الذي تدفع له.» — this picker is what makes that refusal
 * unreachable rather than what replaces it.
 */
function StudentPicker() {
  const [children, setChildren] = useState<PurchaseBeneficiary[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    billing
      .beneficiaries()
      .then((res) => setChildren(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">شراء أرصدة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          اختر الابن الذي تشتري له أولاً — الرصيد يُقيَّد باسمه، والدفع باسمك.
        </p>
      </div>

      {loading && <RowsSkeleton />}

      {!loading && failed && (
        <EmptyState
          title="تعذّر تحميل قائمة الأبناء"
          description="أعد المحاولة بعد قليل."
          action={
            <Button variant="secondary" onClick={load}>
              إعادة المحاولة
            </Button>
          }
        />
      )}

      {/*
        FR-013 — a reason and a way out, never an empty form. An empty list here
        has exactly one cause worth naming: no link has been ACCEPTED yet with
        the «المدفوعات» permission on it. A guardian who added a child by name
        alone, or whose request the child has not answered, holds nothing — and
        «المرتبطون» is the one screen where both are visible and actionable.
      */}
      {!loading && !failed && children.length === 0 && (
        <EmptyState
          title="لا يوجد ابن يمكنك الشراء له"
          description="الشراء نيابةً عن ابن يحتاج ارتباطاً قَبِلَه هو، وتكون فيه صلاحية «المدفوعات» ممنوحة لك. تابع طلباتك المعلّقة من صفحة المرتبطين."
          action={
            <Button variant="primary" href="/family">
              المرتبطون
            </Button>
          }
        />
      )}

      {!loading && !failed && children.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2">
          {children.map((child) => (
            <li key={child.uuid}>
              <Card>
                <h2 className="text-base font-semibold text-ink">{child.name}</h2>

                <div className="mt-4">
                  <Button variant="primary" href={hrefFor(null, child.uuid)}>
                    اختيار
                  </Button>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Which course? — asked here, answered by the server.
 *
 * ⚠️ THIS SCREEN USED TO SEND THE STUDENT BACK TO `/billing`, WHICH SENT THEM
 * HERE. A student with no balance saw "بعد أول عملية شراء ستظهر هنا" on the
 * balance page, whose only purchase link lives inside the balance card that
 * renders only when a balance exists; arriving here without a course, they were
 * told to go back and pick one — from a page that offers no picker. A closed
 * loop with the product's entire revenue path inside it, and the launch default
 * is PREPAID_CREDITS, where a student who cannot buy cannot book.
 */
function CoursePicker({ student }: { student: string | null }) {
  const [courses, setCourses] = useState<PurchasableCourse[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    billing
      .purchasableCourses(student ?? undefined)
      .then((res) => setCourses(res.data ?? []))
      .catch((err: unknown) => {
        setFailed(true);
        setError(errorMessage(err, "تعذّر تحميل الكورسات. أعد المحاولة."));
      })
      .finally(() => setLoading(false));
  }, [student]);

  useEffect(load, [load]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">شراء أرصدة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          اختر الكورس أولاً — سعر الحصة يختلف باختلاف المدرّس، فلا يوجد سعر واحد
          لكل الكورسات.
        </p>
      </div>

      {loading && <RowsSkeleton />}

      {!loading && failed && (
        <EmptyState
          title="تعذّر تحميل الكورسات"
          description={error}
          action={
            <Button variant="secondary" onClick={load}>
              إعادة المحاولة
            </Button>
          }
        />
      )}

      {!loading && !failed && courses.length === 0 && (
        <EmptyState
          title="لا يوجد كورس يمكن شراء أرصدة عليه"
          description="الأرصدة تُشترى على كورس عند مدرّس قائم، فابدأ بالتسجيل في واحد."
          action={
            <Button variant="primary" href="/courses">
              تصفّح الكورسات
            </Button>
          }
        />
      )}

      {!loading && !failed && courses.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2">
          {courses.map((course) => (
            <li key={course.uuid}>
              <Card>
                <h2 className="text-base font-semibold text-ink">{course.title}</h2>

                {course.teacher_name !== null && (
                  <p className="mt-1 text-sm text-ink-muted">{course.teacher_name}</p>
                )}

                <div className="mt-4">
                  <Button variant="primary" href={hrefFor(course.uuid, student)}>
                    عرض الحزم
                  </Button>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default function PurchaseCreditsPage() {
  const router = useRouter();
  const { course, student } = useChoice();
  const { user } = useAuth();

  /*
   * ⚠️ **`dashboardAudience`، لا `platform_role === "parent"`.** الترجمةُ من
   * قيمةِ الخادمِ إلى «أيُّ تخطيطٍ يخصُّك» مكتوبةٌ مرّةً واحدةً هناك، ومقارنةٌ
   * حرفيّةٌ هنا تهجئةٌ ثانيةٌ تفترقُ عن الشريطِ الجانبيِّ عندَ أوّلِ تعديل.
   *
   * ⚠️ **وهذه قراءةُ دَورٍ لا ترشيحُ صلاحيّات**: «هل أنا وليُّ أمر» سؤالٌ عن أيِّ
   * شاشةٍ تُصيَّر، أمّا «لأيِّ ابنٍ يجوزُ لي الدفع» فسؤالُ الخادمِ وحدَه (FR-018).
   * واستنتاجُ الصفةِ من فراغِ القائمةِ خطأٌ ثالث: الطالبُ ووليُّ الأمرِ بلا أبناءٍ
   * يقرآنِ القائمةَ نفسَها الفارغة، ويحتاجانِ شاشتَين مختلفتَين.
   */
  const isGuardian = dashboardAudience(user) === "guardian";

  const [offers, setOffers] = useState<CreditPackageOffer[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [buying, setBuying] = useState<string | null>(null);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    if (course === null) {
      setLoading(false);

      return;
    }

    setLoading(true);
    setFailed(false);

    billing
      .packages(course, student ?? undefined)
      .then((res) => setOffers(res.data ?? []))
      .catch((err: unknown) => {
        setFailed(true);
        setError(errorMessage(err, "تعذّر تحميل الحزم المتاحة. أعد المحاولة."));
      })
      .finally(() => setLoading(false));
  }, [course, student]);

  useEffect(load, [load]);

  function buy(offer: CreditPackageOffer) {
    if (course === null) return;

    setBuying(offer.uuid);
    setError("");

    billing
      .purchase(course, offer.uuid, student ?? undefined)
      .then(() => {
        /*
         * To the orders list, which is where the receipt is uploaded — there is
         * no per-order page, and inventing `/orders/{uuid}` here would have sent
         * every buyer to a 404 on the one screen that follows a payment.
         *
         * A success screen would be worse than a wrong route: it would tell a
         * buyer the credits are theirs when no payment has been approved yet.
         * The next step is uploading the transfer receipt, so the next screen is
         * the one that takes it.
         */
        router.push("/orders");
      })
      .catch((err: unknown) => {
        // 422 carries the reason the Action refused — a retired package, a
        // course that stopped selling, the unredeemed ceiling. Each is
        // actionable and each is already written in Arabic on the server.
        setError(errorMessage(err, "تعذّر بدء الشراء. أعد المحاولة."));
        setBuying(null);
      });
  }

  // Who, then which, then how much — and a guardian cannot skip the first step,
  // exactly as the server cannot answer without it.
  if (isGuardian && student === null) return <StudentPicker />;

  if (course === null) return <CoursePicker student={student} />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">شراء أرصدة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          كل رصيد يعادل حصة واحدة، ولا يُخصم إلا عن حصة قُدِّمت فعلاً.
        </p>
      </div>

      {error && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {error}
        </Alert>
      )}

      {loading && <CardGridSkeleton />}

      {!loading && failed && (
        <EmptyState
          title="تعذّر تحميل الحزم"
          description={error}
          action={
            <Button variant="secondary" onClick={load}>
              إعادة المحاولة
            </Button>
          }
        />
      )}

      {!loading && !failed && offers.length === 0 && (
        <EmptyState
          title="لا توجد حزم متاحة على هذا الكورس حالياً"
          description="تواصل مع مدرّسك لمعرفة موعد إتاحتها."
          action={
            <Button variant="secondary" href="/billing">
              رصيدي
            </Button>
          }
        />
      )}

      {!loading && !failed && offers.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {offers.map((offer) => (
            <li key={offer.uuid}>
              <Card>
                <h2 className="text-base font-semibold text-ink">{offer.name}</h2>

                <p className="mt-2 text-2xl font-extrabold text-ink">
                  <bdi>{formatCredits(offer.credits)}</bdi>{" "}
                  <span className="text-sm font-medium text-ink-muted">حصة</span>
                </p>

                {/* One number. There is no breakdown to expand, and no toggle
                    that would suggest there is. */}
                <p className="mt-1 text-lg font-bold text-ink">
                  {formatMinorMoney(offer.total_minor, offer.currency)}
                </p>

                <p className="mt-2 text-xs text-ink-muted">
                  {offer.validity_days === null
                    ? "لا تنتهي صلاحية الأرصدة."
                    : `صالحة ${offer.validity_days.toLocaleString("ar-EG")} يوماً من الاعتماد.`}
                </p>

                <div className="mt-4">
                  <Button
                    variant="primary"
                    onClick={() => buy(offer)}
                    disabled={buying !== null}
                    loading={buying === offer.uuid}
                    loadingLabel="جارٍ التحضير…"
                  >
                    اختيار هذه الحزمة
                  </Button>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-ink-muted">
        لا يُضاف أي رصيد قبل اعتماد الدفع. بعد اختيار الحزمة يُرفع إيصال التحويل،
        ويظهر الرصيد فور اعتماده.
      </p>
    </div>
  );
}
