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
export function CourseCard({ course }: { course: Course }) {
  return (
    <article className="group flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised transition duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg active:translate-y-0 active:duration-100">
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

        {/* There is no standalone course page yet, so the title leads to the
            teacher's courses tab — where the course can actually be booked —
            rather than to a route that would 404. */}
        <h3 className="text-base font-bold leading-snug text-ink">
          {course.teacher ? (
            <Link
              href={`/teachers/${course.teacher.slug ?? course.teacher.uuid}?tab=courses`}
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
            href={`/teachers/${course.teacher.slug ?? course.teacher.uuid}`}
            className="flex items-center gap-2 text-sm text-ink-muted hover:text-primary-ink"
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
