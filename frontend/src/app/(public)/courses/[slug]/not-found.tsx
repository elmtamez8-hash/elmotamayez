import Link from "next/link";

/**
 * A course that is not there — deleted, unpublished, or a mistyped link.
 *
 * ⚠️ THE GROUP'S OWN 404 SPOKE OF A TEACHER. `(public)/not-found.tsx` is shared
 * by every public page, and it read «أو أن المدرّس لم يعد متاحاً» to somebody
 * who had followed a link to a COURSE. This one says what was missing and offers
 * the course catalogue rather than the teachers list.
 */
export default function CourseNotFound() {
  return (
    <div className="mx-auto flex max-w-2xl flex-col items-center px-4 py-24 text-center sm:px-6">
      <h1 className="mb-3 text-3xl font-extrabold text-ink">الكورس غير متاح</h1>
      <p className="mb-8 leading-relaxed text-ink-muted">
        هذا الكورس غير موجود، أو لم يعد منشوراً على المنصة.
      </p>
      <div className="flex flex-wrap justify-center gap-3">
        <Link
          href="/courses"
          className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
        >
          تصفّح الكورسات
        </Link>
        <Link
          href="/"
          className="rounded-xl border border-line px-5 py-2.5 text-sm font-semibold text-ink transition hover:border-primary hover:text-primary-ink"
        >
          العودة للرئيسية
        </Link>
      </div>
    </div>
  );
}
