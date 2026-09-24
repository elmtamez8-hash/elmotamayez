"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import {
  CertificateIcon,
  CheckIcon,
  ChevronStartIcon,
  LearningIcon,
  MessagesIcon,
} from "@/components/icons";
import { useCourseOwnership } from "@/components/marketplace/CourseOwnership";
import { VerifiedBadgeIcon } from "@/components/icons";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { CoursePrice } from "@/components/marketplace/CoursePrice";
import type { Curriculum } from "@/lib/curriculum";
import type { CourseDetail } from "@/lib/public-api";
import { api } from "@/lib/api";
import { isLearner, useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { counted } from "@/lib/labels";

/**
 * العمود الجانبي — وجهان: مَن يفكّر في الشراء، ومَن اشترى.
 *
 * ⚠️ THE OWNER'S HALF IS THE DOOR THIS PAGE DID NOT HAVE. Everything it shows is
 * READ from the curriculum payload — the percentage, the counts and the item to
 * resume — and none of it is computed here. `resume_lesson_uuid` in particular
 * is the server's answer to «where was I», already null on a finished course AND
 * on one whose first item is shut, so a button built from it can never point at
 * a door that refuses (`CurriculumResource::resumeUuid()` says why).
 */
export function CourseRail({
  priceMinor,
  currency,
  courseUuid,
  isFull,
  freeEnrollment = false,
  enrolmentOpen,
  privateSubscriptionAvailable,
  joinableGroup,
  teacher,
}: {
  priceMinor: number | null;
  currency: string | null;
  courseUuid: string;
  /** حكمُ الخادم، ولا يُشتَقُّ هنا — {@see VisitorRail}. */
  isFull: boolean;
  /** حكمُ الخادم (`free_enrollment`)، ولا يُشتَقُّ من السعر. */
  freeEnrollment?: boolean;
  /** حكمُ الخادم (`enrolment_open`) — {@see VisitorRail}. */
  enrolmentOpen: boolean;
  /** حكمُ الخادم (`private_subscription_available`). */
  privateSubscriptionAvailable: boolean;
  /** أنّ مجموعةً واحدةً على الأقلّ حكمَ لها الخادمُ `is_joinable`. */
  joinableGroup: boolean;
  teacher: CourseDetail["teacher"];
}) {
  const ownership = useCourseOwnership();

  return (
    <div className="flex flex-col gap-4">
      {ownership.state === "owner" ? (
        <OwnerRail data={ownership.data} courseUuid={courseUuid} />
      ) : (
        <VisitorRail
          priceMinor={priceMinor}
          currency={currency}
          isFull={isFull}
          freeEnrollment={freeEnrollment}
          enrolmentOpen={enrolmentOpen}
          privateSubscriptionAvailable={privateSubscriptionAvailable}
          joinableGroup={joinableGroup}
          courseUuid={courseUuid}
        />
      )}

      {/*
        ⚠️ المدرّسُ هنا لا في الترويسة، وهو إصلاحٌ لا إعادةُ ترتيب: العمودُ كانَ
        قرصاً قصيراً معلّقاً في فراغٍ بجوارِ منهجٍ طويل، والمدرّسُ هو الحقيقةُ
        الثانيةُ التي يُقرَّرُ على أساسِها. وبقاؤُه لاصقاً يعني أنّ اسمَه يظلُّ
        أمامَ العينِ وأنتَ تقرأُ منهجَه — وهو أقربُ إلى سببِ وجودِه من موضعِه
        السابقِ أعلى الصفحة.

        ⛔ ومنقولٌ لا مكرّر: نسخةٌ ثانيةٌ منه في الترويسةِ رابطانِ إلى صفحةٍ
        واحدةٍ على شاشةٍ واحدة.
      */}
      {teacher && (
        <Link
          href={`/teachers/${teacher.slug ?? teacher.uuid}`}
          className="flex items-center gap-3 rounded-2xl border border-line bg-surface-raised px-4 py-3.5 transition hover:border-primary hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          {teacher.photo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={teacher.photo_url}
              alt=""
              className="h-11 w-11 shrink-0 rounded-full object-cover"
            />
          ) : (
            <span
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-soft font-black text-primary-ink"
              aria-hidden="true"
            >
              {teacher.name.charAt(0)}
            </span>
          )}

          <span className="flex min-w-0 flex-col gap-1">
            {/* الشارةُ بجوارِ الاسمِ هنا كما في الكارتِ وكما في الملفِّ الشخصيّ. */}
            <span className="flex min-w-0 items-center gap-1.5">
              <span className="truncate text-sm font-bold text-ink">{teacher.name}</span>
              {teacher.is_verified && (
                <VerifiedBadgeIcon
                  className="h-4 w-4 shrink-0 text-secondary-ink"
                  title="مدرّس موثّق"
                />
              )}
            </span>
            <TrustScoreBadge score={teacher.trust_score} band={teacher.trust_score_band} />
          </span>
        </Link>
      )}
    </div>
  );
}

/** ما يشتريه التسجيل — ثلاث جُمَل، لا قائمة تسويق. */
const PROMISES = [
  { Icon: LearningIcon, text: "وصول دائم لكلّ ما يُنشَر في هذا الكورس" },
  { Icon: CertificateIcon, text: "شهادة عند إتمام المنهج" },
  { Icon: MessagesIcon, text: "سؤال المدرّس داخل كلّ درس" },
] as const;

/**
 * ⛔ **الزرُّ يغيبُ حينَ يرفضُه الباب — لا يُعطَّل، ولا يبقى واعداً.**
 *
 * `CreateOrder` يرفضُ كورساً اكتملت مجموعاتُه (٠٣٤ · FR-023)، والعمودُ كانَ
 * يعرضُ «سجّل في الكورس» بالسعرِ فوقَه بلا حرفٍ يقولُ ذلك — فالشاشةُ تَعِدُ بما
 * يرفضُه الباب. قِيسَ بالمشي على الإنتاجِ ٢٠٢٦-٠٩-١٤.
 *
 * ⚠️ **و`isFull` حكمُ الخادمِ يُمرَّرُ كما هو** — هو الحقلُ نفسُه الذي تقرأُه
 * `CohortList`، والذي يقرأُه بابُ الشراءِ من `CohortDirectory::courseIsFull()`.
 * إعادةُ اشتقاقِه هنا («مفتوحةٌ وغيرُ مكتمِلة») إملاءٌ ثانٍ لسؤالٍ واحد، وهو ما
 * جعلَ تسجيلاً مدفوعاً غيرَ قابلٍ للفتحِ في ٠١٨.
 *
 * ⚠️ **ولا زرَّ دَورٍ هنا ولا رابطَ إليه.** الزرُّ الحقيقيُّ في لافتةِ تبويبِ
 * المجموعات، ورابطٌ بـ`?tab=groups` **ميّتٌ من هذه الصفحة**: `useTabParam` يقرأُ
 * الاستعلامَ في `useEffect` بمُعتمَداتٍ ثابتة، فتغييرُ العنوانِ وحدَه لا يُبدِّلُ
 * التبويب. فالعمودُ يقولُ الحقيقةَ ويدلُّ على موضعِ الفعل، ولا يَعِدُ بنقرةٍ لا
 * تقع.
 *
 * ⛔ **ولا سعرَ ولا زرَّ على كورسٍ لا يبيعُه شيء** (قرارُ المالكِ ٢٠٢٦-٠٩-٢٤).
 * المنصّةُ تبيعُ من بابَينِ لا ثالثَ لهما: مجموعةٌ تقبلُ الانضمام، أو دعوةُ
 * الحصصِ الخاصّة. كورسٌ بلا أيٍّ منهما كانَ يعرضُ «٢٥ US$» و«سجّل في الكورس»
 * فوقَ لا شيء — قِيسَ على الإنتاج. `enrolmentOpen` حكمُ الخادمِ مشتقٌّ هناك من
 * الحكمَينِ نفسَيهما، ويُقرَأُ هنا كما هو.
 *
 * ⚠️ **والزرُّ يعرفُ القارئ** (بلاغُ ٢٠٢٦-٠٩-٢٤): كانَ رابطاً إلى
 * `/signup/student` لكلِّ من ليسَ مالكاً، فطالبٌ داخلٌ بحسابِه يُرَدُّ من صفحةِ
 * التسجيلِ إلى الرئيسيّة، وزائرٌ يُنشئُ حسابَه يفقدُ الكورسَ الذي جاءَ لأجلِه.
 * الداخلُ يذهبُ إلى `/subscribe` مباشرةً، والزائرُ إلى التسجيلِ ومعه `next`
 * يُعيدُه إلى الاختيارِ نفسِه — و`StudentSignupForm` يمرِّرُه على `safeNext()`.
 */
function VisitorRail({
  priceMinor,
  currency,
  isFull,
  freeEnrollment,
  enrolmentOpen,
  privateSubscriptionAvailable,
  joinableGroup,
  courseUuid,
}: {
  priceMinor: number | null;
  currency: string | null;
  isFull: boolean;
  freeEnrollment: boolean;
  enrolmentOpen: boolean;
  privateSubscriptionAvailable: boolean;
  joinableGroup: boolean;
  courseUuid: string;
}) {
  /*
    ⚠️ The closed state wins over everything but «full» and «free»: «full» has
    its own sentence and its own door (the waitlist), and a free course is
    entered without buying anything, so neither is «nobody opened it».
  */
  const closed = !isFull && !freeEnrollment && !enrolmentOpen;

  return (
    <aside className="flex flex-col gap-5 rounded-3xl border border-line bg-surface-raised p-6 shadow-sm">
      {/*
        ⛔ ASKED, NEVER RESTATED. «Who may see a price» is `CoursePrice`'s whole
        job — shown to the people who would pay it and to nobody else, a
        signed-out visitor included — and a copy of that condition here is a
        second spelling that drifts at the first edit to either. It renders
        nothing at all when the answer is no, so there is no wrapper to guard.

        ⚠️ Except the one question it cannot ask: whether anything is on sale.
        A price over a course nobody can buy reads as an offer.
      */}
      {!closed && <CoursePrice priceMinor={priceMinor} currency={currency} size="rail" />}

      {isFull ? (
        <p className="flex flex-col gap-1.5 rounded-xl bg-primary-soft px-4 py-3.5 text-sm text-ink">
          <b className="font-extrabold text-primary-ink">اكتملت مجموعات هذا الكورس</b>
          <span className="text-ink-muted">
            لا مكان شاغراً الآن. سجّل في الدَّور من تبويب «المجموعات المتاحة».
          </span>
        </p>
      ) : freeEnrollment ? (
        <FreeEnrollButton courseUuid={courseUuid} />
      ) : closed ? (
        <p className="flex flex-col gap-1.5 rounded-xl bg-primary-soft px-4 py-3.5 text-sm text-ink">
          <b className="font-extrabold text-primary-ink">لم يفتح المدرّس الاشتراك بعد</b>
          <span className="text-ink-muted">
            لا مجموعة ولا باقة متاحة لهذا الكورس الآن. تابع صفحة المدرّس لتعرف حين يفتح
            الاشتراك.
          </span>
        </p>
      ) : (
        <SubscribeWays
          courseUuid={courseUuid}
          privateSubscriptionAvailable={privateSubscriptionAvailable}
          joinableGroup={joinableGroup}
        />
      )}

      <ul className="flex flex-col gap-3">
        {PROMISES.map(({ Icon, text }) => (
          <li key={text} className="flex items-start gap-2.5 text-sm text-ink-muted">
            <span className="mt-0.5 shrink-0 text-secondary-ink" aria-hidden="true">
              <Icon />
            </span>
            {text}
          </li>
        ))}
      </ul>
    </aside>
  );
}

/**
 * The ways in, for a course something IS sold on.
 *
 * ⚠️ A GROUP IS JOINED FROM ITS OWN CARD, NOT FROM HERE. The group's
 * «اشترك» button lives in the groups tab and carries the group's uuid; a
 * rail button cannot know which group the reader wants, and a link to
 * `?tab=groups` is dead from this page (the docblock above says why). So the
 * rail says where the action is.
 *
 * ⚠️ AND NOTHING IS DRAWN WHILE THE SESSION IS BEING RESTORED. `useAuth`
 * starts every page with `user === null`, so the link drawn then is the
 * visitor's — a signed-in student pressing it in that instant is bounced off
 * the signup page, which is the defect this component replaced.
 */
function SubscribeWays({
  courseUuid,
  privateSubscriptionAvailable,
  joinableGroup,
}: {
  courseUuid: string;
  privateSubscriptionAvailable: boolean;
  joinableGroup: boolean;
}) {
  const { user, loading } = useAuth();

  const subscribe = `/subscribe?course=${encodeURIComponent(courseUuid)}`;
  // A teacher or staff account buys nothing here — the door refuses them.
  const mayBuy = user === null || isLearner(user);

  return (
    <div className="flex flex-col gap-3">
      {joinableGroup && (
        <p className="flex flex-col gap-1.5 rounded-xl bg-primary-soft px-4 py-3.5 text-sm text-ink">
          <b className="font-extrabold text-primary-ink">اختر مجموعتك</b>
          <span className="text-ink-muted">
            من تبويب «المجموعات المتاحة» — اضغط «اشترك» تحت المجموعة التي تناسب مواعيدك.
          </span>
        </p>
      )}

      {privateSubscriptionAvailable && !loading && mayBuy && (
        <Link
          href={
            user === null ? `/signup/student?next=${encodeURIComponent(subscribe)}` : subscribe
          }
          className="flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3.5 text-sm font-extrabold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          اشترك بحصص خاصة
        </Link>
      )}
    </div>
  );
}

function OwnerRail({
  data,
  courseUuid,
}: {
  data: Curriculum;
  courseUuid: string;
}) {
  const { progress_pct: pct, completed_count: done, countable_count: total } = data.course;
  const resume = data.course.resume_lesson_uuid;

  return (
    <aside className="flex flex-col gap-5 overflow-hidden rounded-3xl border border-secondary/40 bg-surface-raised shadow-sm">
      {/* اللون هنا معنى لا زينة: أخضرُ الملكيّة، وفوقه الجملة نفسها بالكلمات. */}
      <p className="flex items-center gap-2.5 bg-secondary/15 px-6 py-4 text-sm font-extrabold text-secondary-ink">
        <span aria-hidden="true">
          <CheckIcon />
        </span>
        أنت مسجّل في هذا الكورس
      </p>

      <div className="flex flex-col gap-5 px-6 pb-6">
        <div className="flex flex-col gap-2">
          <p className="flex items-baseline justify-between text-sm text-ink-muted">
            <span>تقدّمك</span>
            <b className="text-base font-extrabold text-ink">
              <bdi>{pct}٪</bdi>
            </b>
          </p>

          {/* ⚠️ الرقم للقارئ الذي لا يرى الشريط — `progressbar` بقيمه، لا `div`
              ملوّن: شريطٌ بلا دور هو حالةٌ يحملها اللون وحده. */}
          <div
            role="progressbar"
            aria-valuenow={pct}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="نسبة إتمام الكورس"
            className="h-2.5 overflow-hidden rounded-full bg-primary-soft"
          >
            <span
              className="block h-full rounded-full bg-secondary transition-[width] duration-700 ease-out"
              style={{ width: `${pct}%` }}
            />
          </div>

          <p className="text-xs text-ink-muted">
            {done === 0
              ? `لم تبدأ بعد · ${counted(total, {
                  one: "عنصر واحد بانتظارك",
                  two: "عنصران بانتظارك",
                  few: "عناصر بانتظارك",
                  many: "عنصراً بانتظارك",
                  other: "عنصر بانتظارك",
                })}`
              : `${done} من ${total}`}
          </p>
        </div>

        {/*
          ⚠️ ABSENT, NEVER DISABLED. `resume_lesson_uuid` is null on a finished
          course and on one whose first item is shut — a greyed button there
          promises something and then refuses it, leaving the reader hunting for
          what they did wrong. The way in below is always there.
        */}
        {resume !== null && (
          <Link
            href={`/learn/${resume}`}
            className="flex items-center justify-center gap-2 rounded-xl bg-secondary px-5 py-3.5 text-sm font-extrabold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary"
          >
            {done === 0 ? "ابدأ الكورس" : "تابع من حيث وقفت"}
            <span aria-hidden="true">
              <ChevronStartIcon />
            </span>
          </Link>
        )}

        <Link
          href={`/enrollments/${courseUuid}`}
          className="flex items-center justify-center gap-2 rounded-xl border border-line px-5 py-3.5 text-sm font-extrabold text-ink transition hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          صفحة الكورس في «تعلّمي»
        </Link>
      </div>
    </aside>
  );
}

/**
 * «سجّل مجاناً» — the free-enrolment door, which had no caller anywhere.
 *
 * ⚠️ DRAWN ONLY ON THE SERVER'S `free_enrollment`, and the door refuses on the
 * same predicate (`courseRequiresPurchase()`), so a course sold by plan never
 * shows it. A guest is sent to sign up; a STUDENT enrols in place. A guardian is
 * not a student — pressing it would enrol the guardian — so they get the
 * ordinary link, and so does a teacher, whom the door refuses anyway.
 */
function FreeEnrollButton({ courseUuid }: { courseUuid: string }) {
  const { user } = useAuth();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);

  const className =
    "flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3.5 text-sm font-extrabold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60";

  if (user?.platform_role !== "student") {
    return (
      // `next` brings a new account back to this course (the uuid 308s to the
      // slug), rather than dropping them on a home page with the course lost.
      <Link
        href={`/signup/student?next=${encodeURIComponent(`/courses/${courseUuid}`)}`}
        className={className}
      >
        سجّل مجاناً
      </Link>
    );
  }

  const enrol = async () => {
    setBusy(true);
    setRefusal(null);

    try {
      await api.post(`/courses/${courseUuid}/enroll`, {});
      router.push(`/enrollments/${courseUuid}`);
    } catch (err) {
      setRefusal(userMessage(err));
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-2">
      <button type="button" className={className} onClick={() => void enrol()} disabled={busy}>
        {busy ? "جارٍ التسجيل…" : "سجّل مجاناً"}
      </button>
      {refusal !== null && (
        <p role="alert" className="text-sm text-danger-ink">
          {refusal}
        </p>
      )}
    </div>
  );
}
