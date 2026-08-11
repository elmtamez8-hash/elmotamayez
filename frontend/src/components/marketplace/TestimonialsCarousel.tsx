"use client";

import { ChevronEndIcon, ChevronStartIcon } from "@/components/icons";
import Link from "next/link";
import { StarRating } from "./StarRating";
import { useState } from "react";

/**
 * A review a student actually wrote — the same shape the teacher's own page
 * publishes, not an authored `{name, role, quote}`.
 */
type Testimonial = {
  student_display_name: string;
  rating: number;
  comment: string;
  created_at: string;
  teacher_uuid: string | null;
  teacher_name: string | null;
  teacher_photo_url: string | null;
};

/**
 * Reviews carousel.
 *
 * All slides stay in the DOM and the inactive ones are hidden with the `hidden`
 * attribute, so the crawler in SC-016 reads every quote even though only one is
 * on screen. Arrow keys move between them (FR-042).
 *
 * ⚠️ It renders nothing on an empty list, and that matters more than it looks:
 * this used to be fed three hardcoded quotes from invented people, so the
 * section could never be empty and never told the truth. Reading real reviews
 * means the section is absent until a student writes one — which is the honest
 * state of a product before launch, and the one PRODUCT.md requires.
 */
export function TestimonialsCarousel({ items }: { items: Testimonial[] }) {
  // ⚠️ Keyed by index deliberately. The obvious key — display name plus
  // timestamp — collides in production, because `studentDisplayName()` is
  // built NOT to be unique: it truncates the family name on purpose so a
  // reviewer cannot be identified to the teacher they just rated. Two students
  // called "أحمد م." reviewing in the same second is normal, not a coincidence.
  // The list is fetched once and never reorders, so the index is stable.
  const [index, setIndex] = useState(0);

  if (items.length === 0) return null;

  const move = (delta: number) =>
    setIndex((current) => (current + delta + items.length) % items.length);

  return (
    <div
      className="mx-auto max-w-3xl"
      role="group"
      aria-roledescription="عارض مراجعات"
      aria-label="مراجعات الطلاب"
      onKeyDown={(event) => {
        // RTL: ArrowLeft advances, ArrowRight goes back.
        if (event.key === "ArrowLeft") move(1);
        if (event.key === "ArrowRight") move(-1);
      }}
    >
      {items.map((item, i) => (
        <figure
          key={i}
          hidden={i !== index}
          aria-roledescription="مراجعة"
          aria-label={`${i + 1} من ${items.length}`}
          // min-h, because every slide is in the DOM and only one is shown: a
          // one-line quote followed by a three-line one made the whole band jump
          // on each arrow press. rounded-3xl to match Card and the pill buttons.
          className="flex min-h-72 flex-col justify-center rounded-3xl border border-line bg-surface-raised p-10 text-center"
        >
          <blockquote className="mb-8 text-xl leading-relaxed text-ink sm:text-2xl">
            «{item.comment}»
          </blockquote>
          {/* The face belongs to the TEACHER, never the reviewer.
              `studentDisplayName()` truncates the family name on purpose so a
              reviewer cannot be identified to the teacher they just rated, and a
              photo beside that truncation would identify them completely. The
              teacher's photo is already published on their own card. */}
          <figcaption className="flex flex-col items-center gap-3">
            {item.teacher_name && (
              <Link
                href={item.teacher_uuid ? `/teachers/${item.teacher_uuid}` : "/teachers"}
                className="flex items-center gap-3 rounded-full py-1 ps-1 pe-4 transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                {item.teacher_photo_url ? (
                  // Plain <img>, not next/image: this URL points at the API host
                  // and next/image refuses a remote host that is not in
                  // remotePatterns. TeacherCard and CourseCard do the same.
                  <img
                    src={item.teacher_photo_url}
                    alt=""
                    className="h-12 w-12 rounded-full object-cover"
                    loading="lazy"
                  />
                ) : (
                  <span
                    className="flex h-12 w-12 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary-ink"
                    aria-hidden="true"
                  >
                    {item.teacher_name.charAt(0)}
                  </span>
                )}
                <span className="text-start">
                  <span className="block text-xs text-ink-muted">مراجعة عن</span>
                  <span className="block font-semibold text-ink">{item.teacher_name}</span>
                </span>
              </Link>
            )}

            <span className="text-sm text-ink-muted">
              {item.student_display_name}
            </span>
            {/* The rating replaces the invented "role". It is a real number the
                reviewer chose, and it is why the quote carries weight. */}
            <StarRating value={item.rating} />
          </figcaption>
        </figure>
      ))}

      <div className="mt-6 flex items-center justify-center gap-3">
        <button
          type="button"
          onClick={() => move(-1)}
          className="rounded-full border border-line p-2 text-ink transition hover:border-primary hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          aria-label="الشهادة السابقة"
        >
          <ChevronStartIcon />
        </button>

        <ul className="flex gap-2">
          {items.map((item, i) => (
            <li key={i}>
              <button
                type="button"
                onClick={() => setIndex(i)}
                aria-current={i === index}
                aria-label={`المراجعة ${i + 1}`}
                className={`h-2.5 w-2.5 rounded-full transition ${
                  i === index ? "bg-primary" : "bg-line hover:bg-ink-muted"
                }`}
              />
            </li>
          ))}
        </ul>

        <button
          type="button"
          onClick={() => move(1)}
          className="rounded-full border border-line p-2 text-ink transition hover:border-primary hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          aria-label="الشهادة التالية"
        >
          <ChevronEndIcon />
        </button>
      </div>
    </div>
  );
}
