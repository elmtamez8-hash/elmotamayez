import type { TeacherDetail } from "@/lib/public-api";
import { StarRating } from "@/components/marketplace/StarRating";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ReviewForm } from "@/components/marketplace/ReviewForm";
import { ReportReviewButton } from "@/components/marketplace/ReportReviewButton";

import { arabicDecimal, arabicNumber } from "@/lib/numerals";
import { counted } from "@/lib/labels";
import { MessagesIcon, QuoteMarkIcon } from "@/components/icons";
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
        className="grid gap-8 rounded-3xl border border-line bg-surface-raised p-6 shadow-sm sm:grid-cols-[auto_1fr] sm:items-center sm:p-8"
      >
        <h2 id="reviews-summary" className="sr-only">
          ملخّص التقييمات
        </h2>

        <div className="text-center sm:border-e sm:border-line sm:pe-10">
          <p className="text-6xl font-extrabold leading-none text-primary-ink">
            {reviews.average === null || reviews.average === undefined
              ? "—"
              : arabicDecimal(reviews.average)}
          </p>
          <div className="mt-3 flex justify-center">
            <StarRating value={reviews.average} count={reviews.total} size="lg" />
          </div>
        </div>

        {/* A definition list, not a chart library: five rows of "how many gave N
            stars" is exactly what <dl> means, and it reads correctly to a screen
            reader without a single aria attribute. */}
        <dl className="space-y-2.5">
          {STARS.map((star) => {
            const count = reviews.distribution[String(star)] ?? 0;
            const share = reviews.total === 0 ? 0 : (count / reviews.total) * 100;

            return (
              <div key={star} className="flex items-center gap-3">
                <dt className="w-24 shrink-0 text-sm font-semibold text-ink-muted">
                  {/* Through `counted()`: «١ نجوم» and «٢ نجوم» are what a
                      template literal prints, and neither is Arabic. */}
                  {counted(star, {
                    one: "نجمة واحدة",
                    two: "نجمتان",
                    few: "نجوم",
                    many: "نجمة",
                    other: "نجمة",
                  })}
                </dt>
                <dd className="flex flex-1 items-center gap-3">
                  <span
                    className="h-2.5 flex-1 overflow-hidden rounded-full bg-line"
                    aria-hidden="true"
                  >
                    <span
                      className="block h-full rounded-full bg-accent"
                      style={{ width: `${share}%` }}
                    />
                  </span>
                  <span className="w-8 shrink-0 text-sm tabular-nums text-ink-muted">
                    {arabicNumber(count)}
                  </span>
                </dd>
              </div>
            );
          })}
        </dl>
      </section>

      <section aria-labelledby="reviews-list">
        <h2 id="reviews-list" className="mb-5 flex items-center gap-3 text-xl font-extrabold text-ink">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
            <MessagesIcon className="h-5 w-5" />
          </span>
          آراء الطلاب
        </h2>

        {/* A wall, not a column: two columns that each keep their own height,
            so a one-line review does not sit in a box sized for a paragraph. */}
        <ul className="gap-4 sm:columns-2 [&>li]:mb-4">
          {reviews.items.map((review) => (
            <li
              key={review.uuid}
              className="group relative isolate break-inside-avoid overflow-hidden rounded-3xl border border-line bg-surface-raised p-6 shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
            >
              <QuoteMarkIcon className="absolute -top-2 end-3 -z-10 h-20 w-20 text-primary-ink/10 transition duration-300 ease-out group-hover:-rotate-6 motion-reduce:transition-none motion-reduce:group-hover:rotate-0" />
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p className="flex items-center gap-3 font-bold text-ink">
                  {review.student_avatar_url ? (
                    // Plain <img>: the URL points at the API host, and next/image
                    // refuses a remote host that is not in remotePatterns.
                    <img
                      src={review.student_avatar_url}
                      alt=""
                      className="h-11 w-11 shrink-0 rounded-full object-cover ring-2 ring-primary-soft"
                      loading="lazy"
                    />
                  ) : (
                    // Same 40px box as the photo it stands in for, so a row with
                    // an avatar and a row without do not sit at different heights.
                    <span
                      className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-soft text-base font-extrabold text-primary-ink"
                      aria-hidden="true"
                    >
                      {review.student_display_name.charAt(0)}
                    </span>
                  )}
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
                <p className="leading-relaxed text-ink">{review.comment}</p>
              )}

              {/* A client leaf: the tab stays server-rendered for crawlers, and
                  only a signed-in reader is offered the control. */}
              <ReportReviewButton reviewUuid={review.uuid} />
            </li>
          ))}
        </ul>
      </section>

      <ReviewForm teacherUuid={teacherUuid} />
    </div>
  );
}
