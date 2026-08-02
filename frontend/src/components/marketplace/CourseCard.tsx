import Link from "next/link";
import type { CourseCard as Course } from "@/lib/public-api";
import { StarRating } from "./StarRating";
import { CURRENCY_LABEL } from "@/lib/platform";

const TYPE_LABELS: Record<Course["type"], string> = {
  individual: "فردي",
  group: "جماعي",
  recorded: "مسجّل",
};

function hours(seconds: number): string {
  const value = Math.round(seconds / 3600);

  return value > 0 ? `${value.toLocaleString("ar-QA")} ساعة` : "—";
}

function discountPercent(price: string, before: string): number {
  return Math.round((1 - Number(price) / Number(before)) * 100);
}

export function CourseCard({ course }: { course: Course }) {
  const saving =
    course.price_before_discount === null
      ? null
      : discountPercent(course.price, course.price_before_discount);

  return (
    <article className="flex flex-col overflow-hidden rounded-2xl border border-line bg-white transition hover:border-primary/40 hover:shadow-sm dark:bg-transparent">
      <div className="relative aspect-video bg-primary-soft">
        {course.cover_url ? (
          <img
            src={course.cover_url}
            alt=""
            className="h-full w-full object-cover"
            loading="lazy"
          />
        ) : (
          <span
            className="flex h-full w-full items-center justify-center text-4xl font-black text-primary/30"
            aria-hidden="true"
          >
            {course.title.charAt(0)}
          </span>
        )}

        {course.is_bestseller && (
          <span className="absolute top-3 start-3 rounded-full bg-accent px-2.5 py-1 text-xs font-bold text-accent-foreground">
            الأكثر طلباً
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col gap-3 p-5">
        <div className="flex items-center gap-2 text-xs">
          <span className="rounded-lg bg-primary-soft px-2 py-0.5 font-medium text-primary">
            {TYPE_LABELS[course.type]}
          </span>
          <span className="text-ink-muted">
            {course.lessons_count.toLocaleString("ar-QA")} حصة ·{" "}
            {hours(course.duration_seconds)}
          </span>
        </div>

        {/* There is no standalone course page yet, so the title leads to the
            teacher's courses tab — where the course can actually be booked —
            rather than to a route that would 404. */}
        <h3 className="text-base font-bold leading-snug text-ink">
          {course.teacher ? (
            <Link
              href={`/teachers/${course.teacher.uuid}?tab=courses`}
              className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {course.title}
            </Link>
          ) : (
            course.title
          )}
        </h3>

        {course.teacher && (
          <Link
            href={`/teachers/${course.teacher.uuid}`}
            className="flex items-center gap-2 text-sm text-ink-muted hover:text-primary"
          >
            {course.teacher.photo_url ? (
              <img
                src={course.teacher.photo_url}
                alt=""
                className="h-6 w-6 rounded-full object-cover"
                loading="lazy"
              />
            ) : (
              <span
                className="flex h-6 w-6 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary"
                aria-hidden="true"
              >
                {course.teacher.name.charAt(0)}
              </span>
            )}
            {course.teacher.name}
          </Link>
        )}

        <div className="flex items-center justify-between gap-2">
          <StarRating value={course.average_rating} />
          <span className="text-xs text-ink-muted">
            {course.enrolled_count.toLocaleString("ar-QA")} طالب
          </span>
        </div>

        <div className="mt-auto flex items-baseline gap-2 pt-2">
          <span className="text-xl font-extrabold text-ink">
            {course.price} <span className="text-sm font-medium">{CURRENCY_LABEL}</span>
          </span>

          {course.price_before_discount && (
            <>
              <s className="text-sm text-ink-muted" aria-hidden="true">
                {course.price_before_discount}
              </s>
              {/* The strike-through is decorative; the saving has to be said out
                  loud for anyone not reading the visual comparison (FR-053). */}
              <span className="sr-only">
                السعر قبل الخصم {course.price_before_discount} {CURRENCY_LABEL}، بخصم{" "}
                {saving}٪
              </span>
              <span className="rounded-lg bg-danger/10 px-2 py-0.5 text-xs font-bold text-danger-ink">
                −{saving}٪
              </span>
            </>
          )}
        </div>
      </div>
    </article>
  );
}
