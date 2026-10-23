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

function Slide({ teacher, hidden }: { teacher: TeacherCard; hidden: boolean }) {
  const subject = teacher.subjects[0];
  const Icon = subject ? subjectIcon(subject) : null;

  return (
    // `inert` takes the off-screen cards out of the tab order and the
    // accessibility tree, so a keyboard never lands on a link nobody can see.
    <article aria-roledescription="slide" aria-hidden={hidden} inert={hidden} className="w-full shrink-0">
      <div className="relative aspect-[4/3] bg-primary-soft">
        {teacher.photo_url ? (
          <img src={teacher.photo_url} alt="" className="h-full w-full object-cover" />
        ) : (
          <span
            className="flex h-full w-full items-center justify-center text-6xl font-extrabold text-primary-ink"
            aria-hidden="true"
          >
            {initialsOf(teacher.name)}
          </span>
        )}
        <span className="absolute start-3 top-3 rounded-full bg-surface-raised/90 px-3 py-1 text-xs font-bold text-primary-ink shadow-sm">
          مدرّس مميّز
        </span>
        {/* The chip is a 15% tint made for a white card; on a photo it needs
            solid ground under it, like «مدرّس مميّز» beside it. */}
        {teacher.available_now && (
          <span className="absolute end-3 top-3 rounded-full bg-surface-raised shadow-sm">
            <AvailableNowChip />
          </span>
        )}
      </div>

      <div className="p-5">
        <h2 className="flex items-center gap-1.5 text-xl font-extrabold text-ink">
          <span className="truncate">{teacher.name}</span>
          {teacher.is_verified && (
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
          <StarRating value={teacher.average_rating} count={teacher.reviews_count} />
        </div>
        <Link
          href={`/teachers/${teacher.slug ?? teacher.uuid}`}
          className="mt-5 block rounded-xl bg-accent px-4 py-3 text-center text-sm font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
          عرض ملف المدرّس
        </Link>
      </div>
    </article>
  );
}

/*
 * The home hero's featured teacher, as a slider over the top five.
 *
 * Owner decision 2026-10-23: no single teacher owns the hero for every
 * visitor, so the card takes turns. It starts on the first (the page is static,
 * `revalidate = 60`, and a random start would swap the card after hydration),
 * advances on its own, and stops while the reader's pointer or focus is inside
 * it — and never moves on its own for a reader who asked for reduced motion.
 *
 * ONE TRACK, NOT ONE CARD RE-MOUNTED (owner, same day): the cards sit side by
 * side and the track moves, so the incoming card pushes the outgoing one out.
 * The track is a flex row in an RTL page, so card N sits N widths to the LEFT
 * and the track moves RIGHT (positive X) to bring it in.
 */
export function HeroSpotlight({ teachers, total }: { teachers: TeacherCard[]; total: number }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const count = teachers.length;
  const go = (step: number) => setIndex((i) => (i + step + count) % count);

  useEffect(() => {
    if (paused || count < 2 || window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) return;
    const timer = window.setInterval(() => setIndex((i) => (i + 1) % count), ADVANCE_MS);
    return () => window.clearInterval(timer);
  }, [paused, count]);

  const arrow =
    "pointer-events-auto flex h-9 w-9 items-center justify-center rounded-full bg-surface-raised/90 text-ink shadow-md transition hover:bg-surface-raised focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

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

      <div className="relative mx-4 overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-xl shadow-primary/15">
        <div
          className="flex transition-transform duration-500 ease-[cubic-bezier(0.65,0,0.35,1)]"
          style={{ transform: `translateX(${index * 100}%)` }}
        >
          {teachers.map((teacher, i) => (
            <Slide key={teacher.uuid} teacher={teacher} hidden={i !== index} />
          ))}
        </div>

        {/* The arrows stay put while the track moves under them, centred on
            the photo — which is the top 4:3 of every card. */}
        {count > 1 && (
          <div className="pointer-events-none absolute inset-x-0 top-0 flex aspect-[4/3] items-center justify-between px-3">
            <button type="button" onClick={() => go(-1)} aria-label="المدرّس السابق" className={arrow}>
              <ChevronStartIcon className="h-5 w-5" />
            </button>
            <button type="button" onClick={() => go(1)} aria-label="المدرّس التالي" className={arrow}>
              <ChevronEndIcon className="h-5 w-5" />
            </button>
          </div>
        )}
      </div>

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
