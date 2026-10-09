import Link from "next/link";
import { QuoteMarkIcon } from "@/components/icons";
import { StarRating } from "./StarRating";

/**
 * A review a student actually wrote — the same shape the teacher's own page
 * publishes, not an authored `{name, role, quote}`.
 */
type Testimonial = {
  student_display_name: string;
  rating: number;
  comment: string;
  created_at: string;
  teacher_slug: string | null;
  teacher_name: string | null;
  teacher_photo_url: string | null;
};

/**
 * Reviews as a wall, not a carousel: every quote is on screen at once, so the
 * section reads as a crowd of voices rather than one slide nobody advances —
 * and the crawler in SC-016 reads every quote without hidden slides.
 *
 * The first review is the featured one (two columns by two rows, burgundy,
 * large type); the rest fill around it. With the six the home payload carries
 * that is a full 3 × 3 at `lg`.
 *
 * ⚠️ It renders nothing on an empty list, and that matters more than it looks:
 * this used to be fed three hardcoded quotes from invented people, so the
 * section could never be empty and never told the truth. Reading real reviews
 * means the section is absent until a student writes one — which is the honest
 * state of a product before launch, and the one PRODUCT.md requires.
 */
export function TestimonialsWall({ items }: { items: Testimonial[] }) {
  if (items.length === 0) return null;

  // ⚠️ Keyed by index deliberately. The obvious key — display name plus
  // timestamp — collides in production, because `studentDisplayName()` is
  // built NOT to be unique: it truncates the family name on purpose so a
  // reviewer cannot be identified to the teacher they just rated. Two students
  // called "أحمد م." reviewing in the same second is normal, not a coincidence.
  // The list is fetched once and never reorders, so the index is stable.
  return (
    <ul className="grid gap-5 md:grid-cols-2 lg:grid-cols-3" aria-label="مراجعات الطلاب">
      {items.map((item, i) => {
        const featured = i === 0;

        return (
          <li
            key={i}
            className={`reveal ${featured ? "md:col-span-2 lg:row-span-2" : ""}`}
          >
            <figure
              className={`relative isolate flex h-full flex-col overflow-hidden rounded-3xl p-7 ${
                featured
                  ? "bg-squares bg-primary text-white shadow-xl shadow-primary/20 sm:p-10"
                  : "border border-line bg-surface shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 motion-reduce:transition-none"
              }`}
            >
              {/* The quotation mark, oversized and faint, behind the words. */}
              <QuoteMarkIcon
                className={`pointer-events-none absolute -z-10 ${
                  featured
                    ? "-bottom-12 -start-8 h-72 w-72 text-white/10"
                    : "-top-4 -end-4 h-28 w-28 text-primary-ink/10"
                }`}
              />

              {/* The rating replaces the invented "role". It is a real number
                  the reviewer chose, and it is why the quote carries weight. */}
              <div className="mb-5">
                <StarRating value={item.rating} tone={featured ? "overlay" : "default"} />
              </div>

              <blockquote
                className={`mb-8 flex-1 ${
                  featured
                    ? "flex items-center text-2xl font-extrabold leading-relaxed sm:text-4xl sm:leading-snug"
                    : "text-base leading-loose text-ink"
                }`}
              >
                {item.comment}
              </blockquote>

              {/* The face belongs to the TEACHER, never the reviewer.
                  `studentDisplayName()` truncates the family name on purpose so
                  a reviewer cannot be identified to the teacher they just rated,
                  and a photo beside that truncation would identify them
                  completely. The teacher's photo is already published on their
                  own card. */}
              <figcaption
                className={`flex flex-wrap items-center justify-between gap-3 border-t pt-5 ${
                  featured ? "border-white/20" : "border-line"
                }`}
              >
                {item.teacher_name ? (
                  <Link
                    href={item.teacher_slug ? `/teachers/${item.teacher_slug}` : "/teachers"}
                    className={`group/teacher flex items-center gap-3 rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
                      featured ? "focus-visible:outline-white" : "focus-visible:outline-primary"
                    }`}
                  >
                    {item.teacher_photo_url ? (
                      // Plain <img>, not next/image: this URL points at the API
                      // host and next/image refuses a remote host that is not in
                      // remotePatterns. TeacherCard and CourseCard do the same.
                      <img
                        src={item.teacher_photo_url}
                        alt=""
                        className={`rounded-full object-cover ring-2 ${
                          featured ? "h-14 w-14 ring-accent" : "h-11 w-11 ring-primary-soft"
                        }`}
                        loading="lazy"
                      />
                    ) : (
                      <span
                        className={`flex items-center justify-center rounded-full font-bold ${
                          featured
                            ? "h-14 w-14 bg-accent text-accent-foreground"
                            : "h-11 w-11 bg-primary-soft text-sm text-primary-ink"
                        }`}
                        aria-hidden="true"
                      >
                        {item.teacher_name.charAt(0)}
                      </span>
                    )}
                    <span className="text-start">
                      <span className={`block text-xs ${featured ? "text-white/70" : "text-ink-muted"}`}>
                        مراجعة عن
                      </span>
                      <span
                        className={`block font-bold underline-offset-4 group-hover/teacher:underline ${
                          featured ? "text-lg text-white" : "text-ink"
                        }`}
                      >
                        {item.teacher_name}
                      </span>
                    </span>
                  </Link>
                ) : (
                  <span />
                )}

                <span className={`text-sm font-semibold ${featured ? "text-white/80" : "text-ink-muted"}`}>
                  {item.student_display_name}
                </span>
              </figcaption>
            </figure>
          </li>
        );
      })}
    </ul>
  );
}
