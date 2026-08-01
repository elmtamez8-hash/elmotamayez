import type { Metadata } from "next";
import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: `طلبك قيد المراجعة — ${PLATFORM_NAME}`,
  // Not a page anyone should reach from search: it only means anything to the
  // person who just submitted.
  robots: { index: false },
};

const REVIEW_DAYS = 3;

const NEXT_STEPS = [
  "يراجع فريقنا الأكاديمي مؤهلاتك وخبرتك.",
  "قد نتواصل معك لطلب المستندات عبر قناة آمنة.",
  "يصلك القرار على بريدك الإلكتروني، وإن احتجنا تعديلاً ستجد الطلب مفتوحاً لك مرة أخرى.",
];

export default function TeacherApplicationSubmittedPage() {
  return (
    <div className="mx-auto max-w-xl px-4 py-20 text-center sm:px-6">
      <span
        className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-secondary/15"
        aria-hidden="true"
      >
        <svg className="h-8 w-8 text-secondary" viewBox="0 0 20 20" fill="currentColor">
          <path
            fillRule="evenodd"
            d="M16.4 6.4a1 1 0 010 1.4l-6.6 6.6a1 1 0 01-1.4 0L5.1 11.1a1 1 0 111.4-1.4l2.6 2.6 5.9-5.9a1 1 0 011.4 0z"
            clipRule="evenodd"
          />
        </svg>
      </span>

      <h1 className="mb-3 text-2xl font-extrabold text-ink sm:text-3xl">
        طلبك قيد المراجعة من فريقنا الأكاديمي
      </h1>

      {/* A number, not "soon": the applicant is deciding whether to wait or look
          elsewhere, and a vague promise is the least useful answer. */}
      <p className="mb-8 text-ink-muted">
        عادةً نردّ خلال {REVIEW_DAYS} أيام عمل. لا حاجة لإعادة الإرسال.
      </p>

      <ol className="mb-10 space-y-3 text-start">
        {NEXT_STEPS.map((item, index) => (
          <li
            key={item}
            className="flex gap-3 rounded-xl border border-line p-4 text-sm text-ink-muted"
          >
            <span
              className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white"
              aria-hidden="true"
            >
              {index + 1}
            </span>
            {item}
          </li>
        ))}
      </ol>

      <div className="flex flex-wrap justify-center gap-3">
        <Link
          href="/teachers"
          className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
        >
          تصفّح المنصة
        </Link>
        <Link
          href="/signup/teacher"
          className="rounded-xl border border-line px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-primary-soft"
        >
          مراجعة طلبي
        </Link>
      </div>
    </div>
  );
}
