"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ChevronEndIcon, ChevronStartIcon, VerifiedBadgeIcon } from "@/components/icons";
import { counted, TEACHERS } from "@/lib/labels";
import type { TeacherCard } from "@/lib/public-api";
import { AvailableNowChip } from "./AvailableNow";
import { StarRating } from "./StarRating";
import { subjectIcon } from "./subject-icon";

const ADVANCE_MS = 5000;

function initialsOf(name: string): string {
  return name.split(" ").slice(0, 2).map((part) => part.charAt(0)).join("");
}

/*
 * The home hero's featured teacher, as a slider over the top five.
 *
 * Owner decision 2026-10-23: no single teacher may own the hero for every
 * visitor, so the card takes turns. It starts on the first (the page is static,
 * `revalidate = 60`, and a random start would swap the card after hydration),
 * advances on its own, and stops while the reader's pointer or focus is inside
 * it — and never moves on its own for a reader who asked for reduced motion.
 * The row of faces under the card is the slider's own set of dots.
 */
export function HeroSpotlight({ teachers, total }: { teachers: TeacherCard[]; total: number }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const count = teachers.length;

  useEffect(() => {
    if (paused || count < 2 || window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) return;
    const timer = window.setInterval(() => setIndex((i) => (i + 1) % count), ADVANCE_MS);
    return () => window.clearInterval(timer);
  }, [paused, count]);

  const lead = teachers[index];
  const subject = lead.subjects[0];
  const Icon = subject ? subjectIcon(subject) : null;
  const go = (step: number) => setIndex((i) => (i + step + count) % count);

  return (
    <section
      aria-roledescription="carousel"
      aria-label="مدرّسون مميّزون"
      className="relative isolate mx-auto w-full max-w-md min-w-0 py-8"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <div
        className="bg-squares absolute inset-x-8 inset-y-0 -z-10 rounded-[2rem] bg-primary"
        aria-hidden="true"
      />

      <article
        key={lead.uuid}
        aria-roledescription="slide"
        aria-label={`${index + 1} من ${count}`}
        className="mx-4 overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-xl shadow-primary/15 animate-float-in"
      >
        <div className="relative aspect-[4/3] bg-primary-soft">
          {lead.photo_url ? (
            <img src={lead.photo_url} alt="" className="h-full w-full object-cover" />
          ) : (
            <span
              className="flex h-full w-full items-center justify-center text-6xl font-extrabold text-primary-ink"
              aria-hidden="true"
            >
              {initialsOf(lead.name)}
            </span>
          )}
          <span className="absolute start-3 top-3 rounded-full bg-surface-raised/90 px-3 py-1 text-xs font-bold text-primary-ink shadow-sm">
            مدرّس مميّز
          </span>
          {lead.available_now && (
            <span className="absolute end-3 top-3">
              <AvailableNowChip />
            </span>
          )}

          {count > 1 && (
            <>
              <button
                type="button"
                onClick={() => go(-1)}
                aria-label="المدرّس السابق"
                className="absolute start-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-surface-raised/90 text-ink shadow-md transition hover:bg-surface-raised focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              >
                <ChevronStartIcon className="h-5 w-5" />
              </button>
              <button
                type="button"
                onClick={() => go(1)}
                aria-label="المدرّس التالي"
                className="absolute end-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-surface-raised/90 text-ink shadow-md transition hover:bg-surface-raised focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              >
                <ChevronEndIcon className="h-5 w-5" />
              </button>
            </>
          )}
        </div>

        <div className="p-5">
          <h2 className="flex items-center gap-1.5 text-xl font-extrabold text-ink">
            <span className="truncate">{lead.name}</span>
            {lead.is_verified && (
              <VerifiedBadgeIcon className="h-5 w-5 shrink-0 text-secondary-ink" title="مدرّس موثّق" />
            )}
          </h2>
          <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-muted">
            {subject && Icon && (
              <span className="inline-flex items-center gap-1">
                <Icon className="h-4 w-4" />
                {subject.name}
              </span>
            )}
            <StarRating value={lead.average_rating} count={lead.reviews_count} />
          </div>
          <Link
            href={`/teachers/${lead.slug ?? lead.uuid}`}
            className="mt-5 block rounded-xl bg-accent px-4 py-3 text-center text-sm font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            عرض ملف المدرّس
          </Link>
        </div>
      </article>

      <div className="mx-4 mt-5 flex flex-wrap items-center justify-center gap-3 text-sm font-semibold text-white">
        {count > 1 && (
          <span className="flex gap-1.5">
            {teachers.map((teacher, i) => (
              <button
                key={teacher.uuid}
                type="button"
                onClick={() => setIndex(i)}
                aria-label={`اعرض ${teacher.name}`}
                aria-current={i === index ? "true" : undefined}
                className={`h-9 w-9 overflow-hidden rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white ${
                  i === index ? "ring-2 ring-white" : "opacity-60 hover:opacity-100"
                }`}
              >
                {teacher.photo_url ? (
                  <img src={teacher.photo_url} alt="" className="h-full w-full object-cover" />
                ) : (
                  <span className="flex h-full w-full items-center justify-center bg-primary-soft text-xs font-extrabold text-primary-ink">
                    {initialsOf(teacher.name)}
                  </span>
                )}
              </button>
            ))}
          </span>
        )}
        <Link href="/teachers" className="underline-offset-4 hover:underline">
          {total > 0 ? `${counted(total, TEACHERS)} على المنصّة` : "عرض كل المدرّسين"}
        </Link>
      </div>
    </section>
  );
}
