import Link from "next/link";
import type { CourseCard as Course } from "@/lib/public-api";
import { StarRating } from "./StarRating";

const TYPE_LABELS: Record<Course["type"], string> = {
  individual: "فردي",
  group: "جماعي",
  recorded: "مسجّل",
};

function hours(seconds: number): string {
  const value = Math.round(seconds / 3600);

  return value > 0 ? `${value.toLocaleString("ar-QA")} ساعة` : "—";
}

/*
 * ⚠️ No price, and no discount badge with it (spec 006, FR-021هـ · T089أ).
 *
 * A card in a list is a browsing surface; the price belongs on the buyable unit,
 * which is the course's own page. The API stopped sending both fields, so the
 * badge could not be rendered here even if the rule changed back — it would need
 * the payload to change first, which is the right order.
 */
/**
 * @param anchor A fragment appended to the card's own link, e.g. `#groups`.
 *
 * ⚠️ ONLY THE TITLE LINK TAKES IT. The byline below points at the TEACHER, and
 * a `#groups` glued to that href would send a reader to a fragment that does not
 * exist on the profile — a link that silently does nothing, which is worse than
 * one that goes somewhere wrong. Default empty, so the marketplace listing and
 * the profile's courses tab are byte-identical to what they were.
 */
export function CourseCard({ course, anchor = "" }: { course: Course; anchor?: string }) {
  return (
    <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised transition duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg active:translate-y-0 active:duration-100">
      <div className="relative aspect-video bg-primary-soft">
        {course.cover_url ? (
          <img
            src={course.cover_url}
            alt=""
            // The cover is what the card is ABOUT, so it is the thing that moves.
            // 400ms and a 4% scale: slow and small enough to read as the image
            // breathing, not as a zoom effect applied to a photo.
            className="h-full w-full object-cover transition duration-[400ms] ease-out group-hover:scale-[1.04]"
            loading="lazy"
          />
        ) : (
          <span
            className="flex h-full w-full items-center justify-center text-4xl font-black text-primary-ink/30"
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
          <span className="rounded-lg bg-primary-soft px-2 py-0.5 font-medium text-primary-ink">
            {TYPE_LABELS[course.type]}
          </span>
          <span className="text-ink-muted">
            {course.lessons_count.toLocaleString("ar-QA")} حصة ·{" "}
            {hours(course.duration_seconds)}
          </span>
        </div>

        {/* ⚠️ THE WHOLE CARD, IN ONE CLICK (spec 023 · SC-001).
            The title used to lead to the teacher's courses tab, which cost three
            clicks to reach the course it was already naming — and the card body
            led nowhere at all, so most of the surface a thumb lands on did
            nothing. The `after:` overlay stretches this one link across the
            article; the byline below sits above it on the z-axis so «who teaches
            this» stays a separate destination rather than being swallowed. */}
        <h3 className="text-base font-bold leading-snug text-ink">
          <Link
            href={`/courses/${course.uuid}${anchor}`}
            className="after:absolute after:inset-0 after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {course.title}
          </Link>
        </h3>

        {course.teacher && (
          <Link
            href={`/teachers/${course.teacher.slug ?? course.teacher.uuid}`}
            className="relative z-10 flex w-fit items-center gap-2 text-sm text-ink-muted hover:text-primary-ink"
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
                className="flex h-6 w-6 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary-ink"
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
          <span className="text-sm font-semibold text-primary-ink">
            عرض التفاصيل والسعر
          </span>
        </div>
      </div>
    </article>
  );
}
