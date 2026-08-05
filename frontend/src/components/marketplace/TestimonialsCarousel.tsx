"use client";

import { ChevronEndIcon, ChevronStartIcon } from "@/components/icons";
import { useState } from "react";

type Testimonial = {
  name: string;
  role: string;
  quote: string;
  photo_url: string | null;
};

/**
 * Testimonials carousel.
 *
 * All slides stay in the DOM and the inactive ones are hidden with the `hidden`
 * attribute, so the crawler in SC-016 reads every quote even though only one is
 * on screen. Arrow keys move between them (FR-042).
 */
export function TestimonialsCarousel({ items }: { items: Testimonial[] }) {
  const [index, setIndex] = useState(0);

  if (items.length === 0) return null;

  const move = (delta: number) =>
    setIndex((current) => (current + delta + items.length) % items.length);

  return (
    <div
      className="mx-auto max-w-3xl"
      role="group"
      aria-roledescription="عارض شهادات"
      aria-label="آراء الطلاب وأولياء الأمور"
      onKeyDown={(event) => {
        // RTL: ArrowLeft advances, ArrowRight goes back.
        if (event.key === "ArrowLeft") move(1);
        if (event.key === "ArrowRight") move(-1);
      }}
    >
      {items.map((item, i) => (
        <figure
          key={item.name}
          hidden={i !== index}
          aria-roledescription="شهادة"
          aria-label={`${i + 1} من ${items.length}`}
          className="rounded-2xl border border-line bg-surface-raised p-8 text-center"
        >
          <blockquote className="mb-6 text-lg leading-relaxed text-ink">
            «{item.quote}»
          </blockquote>
          <figcaption className="flex flex-col items-center gap-2">
            <span
              className="flex h-12 w-12 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary-ink"
              aria-hidden="true"
            >
              {item.name.charAt(0)}
            </span>
            <span className="font-semibold text-ink">{item.name}</span>
            <span className="text-sm text-ink-muted">{item.role}</span>
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
            <li key={item.name}>
              <button
                type="button"
                onClick={() => setIndex(i)}
                aria-current={i === index}
                aria-label={`الشهادة ${i + 1}`}
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
