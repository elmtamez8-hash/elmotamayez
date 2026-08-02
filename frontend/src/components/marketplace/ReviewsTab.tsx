import type { TeacherDetail } from "@/lib/public-api";
import { StarRating } from "@/components/marketplace/StarRating";
import { EmptyState } from "@/components/marketplace/states/EmptyState";
import { ReviewForm } from "@/components/marketplace/ReviewForm";

const STARS = [5, 4, 3, 2, 1] as const;

/**
 * Average, star distribution and the comments, newest first.
 *
 * Server-rendered: reviews are the single strongest reason a visitor picks one
 * teacher over another, so they have to be in the HTML a crawler sees (SC-016).
 * Only the form below is interactive.
 */
export function ReviewsTab({
  teacherUuid,
  reviews,
}: {
  teacherUuid: string;
  reviews: TeacherDetail["reviews"];
}) {
  if (reviews.total === 0) {
    return (
      <div className="space-y-8">
        <EmptyState
          title="لا توجد تقييمات بعد"
          description="لم يقيّم أي طالب هذا المدرّس حتى الآن. التقييمات تظهر بعد إتمام الحصص."
        />
        <ReviewForm teacherUuid={teacherUuid} />
      </div>
    );
  }

  return (
    <div className="space-y-10">
      <section
        aria-labelledby="reviews-summary"
        className="grid gap-8 rounded-2xl border border-line p-6 sm:grid-cols-[auto_1fr]"
      >
        <h2 id="reviews-summary" className="sr-only">
          ملخّص التقييمات
        </h2>

        <div className="text-center sm:border-e sm:border-line sm:pe-8">
          <p className="text-5xl font-extrabold text-ink">
            {reviews.average?.toLocaleString("ar-QA", {
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            })}
          </p>
          <div className="mt-2 flex justify-center">
            <StarRating value={reviews.average} count={reviews.total} size="lg" />
          </div>
        </div>

        {/* A definition list, not a chart library: five rows of "how many gave N
            stars" is exactly what <dl> means, and it reads correctly to a screen
            reader without a single aria attribute. */}
        <dl className="space-y-2">
          {STARS.map((star) => {
            const count = reviews.distribution[String(star)] ?? 0;
            const share = reviews.total === 0 ? 0 : (count / reviews.total) * 100;

            return (
              <div key={star} className="flex items-center gap-3">
                <dt className="w-16 shrink-0 text-sm text-ink-muted">
                  {star} نجوم
                </dt>
                <dd className="flex flex-1 items-center gap-3">
                  <span
                    className="h-2 flex-1 overflow-hidden rounded-full bg-line"
                    aria-hidden="true"
                  >
                    <span
                      className="block h-full rounded-full bg-accent"
                      style={{ width: `${share}%` }}
                    />
                  </span>
                  <span className="w-8 shrink-0 text-sm tabular-nums text-ink-muted">
                    {count.toLocaleString("ar-QA")}
                  </span>
                </dd>
              </div>
            );
          })}
        </dl>
      </section>

      <section aria-labelledby="reviews-list">
        <h2 id="reviews-list" className="mb-4 text-lg font-bold text-ink">
          آراء الطلاب
        </h2>

        <ul className="space-y-4">
          {reviews.items.map((review, index) => (
            <li
              key={`${review.student_display_name}-${review.created_at}-${index}`}
              className="rounded-xl border border-line p-5"
            >
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-ink">
                  {review.student_display_name}
                </p>
                <time
                  dateTime={review.created_at}
                  className="text-sm text-ink-muted"
                >
                  {new Date(review.created_at).toLocaleDateString("ar-QA", {
                    year: "numeric",
                    month: "long",
                    day: "numeric",
                  })}
                </time>
              </div>

              <div className="mb-3">
                <StarRating value={review.rating} />
              </div>

              {review.comment && (
                <p className="leading-relaxed text-ink-muted">{review.comment}</p>
              )}
            </li>
          ))}
        </ul>
      </section>

      <ReviewForm teacherUuid={teacherUuid} />
    </div>
  );
}
