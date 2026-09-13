"use client";

import Link from "next/link";

import {
  CertificateIcon,
  CheckIcon,
  ChevronStartIcon,
  LearningIcon,
  MessagesIcon,
} from "@/components/icons";
import { useCourseOwnership } from "@/components/marketplace/CourseOwnership";
import { TrustScoreBadge } from "@/components/marketplace/TrustScoreBadge";
import { CoursePrice } from "@/components/marketplace/CoursePrice";
import type { Curriculum } from "@/lib/curriculum";
import type { CourseDetail } from "@/lib/public-api";
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
  teacher,
}: {
  priceMinor: number | null;
  currency: string | null;
  courseUuid: string;
  teacher: CourseDetail["teacher"];
}) {
  const ownership = useCourseOwnership();

  return (
    <div className="flex flex-col gap-4">
      {ownership.state === "owner" ? (
        <OwnerRail data={ownership.data} courseUuid={courseUuid} />
      ) : (
        <VisitorRail priceMinor={priceMinor} currency={currency} />
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
            <span className="truncate text-sm font-bold text-ink">{teacher.name}</span>
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

function VisitorRail({
  priceMinor,
  currency,
}: {
  priceMinor: number | null;
  currency: string | null;
}) {
  return (
    <aside className="flex flex-col gap-5 rounded-3xl border border-line bg-surface-raised p-6 shadow-sm">
      {/*
        ⛔ ASKED, NEVER RESTATED. «Who may see a price» is `CoursePrice`'s whole
        job — shown to the people who would pay it and to nobody else, a
        signed-out visitor included — and a copy of that condition here is a
        second spelling that drifts at the first edit to either. It renders
        nothing at all when the answer is no, so there is no wrapper to guard.
      */}
      <CoursePrice priceMinor={priceMinor} currency={currency} size="rail" />

      <Link
        href="/signup/student"
        className="flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3.5 text-sm font-extrabold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        سجّل في الكورس
      </Link>

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
