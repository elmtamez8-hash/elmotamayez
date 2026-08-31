"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

/**
 * One reason to sign up, addressed to one kind of person.
 *
 * `href` is optional and is what turns «تحفيز كل شخص بطريقته» from a sentence
 * into a route: a parent reading the parent slide can go straight to the parent
 * form instead of finding it in a chooser.
 */
export type AuthSlide = {
  title: string;
  body: string;
  href?: string;
  linkLabel?: string;
};

/** Six seconds: long enough to finish a sentence, short enough to see a second one. */
const INTERVAL_MS = 6000;

/**
 * The rotating panel over the photograph.
 *
 * ⚠️ IT FOLLOWS `TestimonialsCarousel`, NOT ITS OWN INVENTION. The dots are
 * `<button type="button">` with `aria-current` and an Arabic `aria-label`; the
 * wrapper is a `role="group"` with an `aria-roledescription`; ArrowLeft advances
 * because this is RTL. Two carousels with two spellings of «which one is showing»
 * is the defect this repository records six times over.
 *
 * Two things are deliberately NOT copied. That component has no auto-advance,
 * because a review is content a reader chooses to page through; this is marketing
 * copy beside a form, which nobody would ever click. And it keeps every slide in
 * the DOM behind `hidden` so SC-016's crawler reads all of them — there is no
 * crawler on a sign-in screen, and rendering only the active slide is what lets
 * its arrival ANIMATE: a hidden element that is merely revealed never re-runs its
 * keyframes. Each slide's title is still in the DOM regardless, inside its own
 * dot's `aria-label`.
 *
 * ⚠️ AND AUTO-ADVANCE IS OFF UNDER `prefers-reduced-motion`. A panel that changes
 * itself every six seconds beside a password field is exactly what that setting
 * exists to stop; the dots still work, so nothing is unreachable — the motion is
 * removed, not the content.
 *
 * ⚠️ THE TRANSITION IS OPACITY, NEVER A TRANSLATE. A horizontal slide is a
 * direction written in CSS, and this product puts direction in the document
 * (spec 002) — a translate carousel needs an RTL branch, which is the thing the
 * whole layout convention exists to avoid. A crossfade has no side.
 *
 * Pausing on hover AND on focus-within: a keyboard reader tabbing to a dot is
 * reading, exactly like a pointer resting on the panel, and a slide that moves
 * out from under the focused control is a control that changes meaning mid-press.
 */
export function AuthSlides({ slides }: { slides: AuthSlide[] }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);

  useEffect(() => {
    if (paused || slides.length < 2) return;

    // The motion query is read here rather than at render: a server render has
    // no `matchMedia`, and reading it in an effect keeps the markup identical on
    // both sides of hydration.
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) return;

    // ⚠️ FUNCTIONAL UPDATE. `setIndex(index + 1)` closes over the index this
    // effect ran with, so the interval would move from 0 to 1 and stop there for
    // ever — a carousel with exactly two slides that looks like it works.
    const id = setInterval(
      () => setIndex((current) => (current + 1) % slides.length),
      INTERVAL_MS,
    );

    return () => clearInterval(id);
  }, [paused, slides.length]);

  if (slides.length === 0) return null;

  const move = (delta: number) =>
    setIndex((current) => (current + delta + slides.length) % slides.length);

  return (
    <div
      role="group"
      aria-roledescription="عارض"
      aria-label="لماذا تنضمّ إلى المنصّة"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
      onKeyDown={(event) => {
        // RTL: ArrowLeft advances, ArrowRight goes back — the same mapping
        // `TestimonialsCarousel` uses.
        if (event.key === "ArrowLeft") move(1);
        if (event.key === "ArrowRight") move(-1);
      }}
    >
      {/*
       * ⚠️ `key={index}` IS THE ANIMATION. React reuses an element whose key did
       * not change, so without it the text swaps in place and `banner-rise` — a
       * mount animation — never runs again after the first slide.
       *
       * `banner-rise` rather than a new keyframe: it is the fade-and-rise this
       * product already uses for content arriving, and the global
       * `prefers-reduced-motion` block in `globals.css` already zeroes it. A
       * second keyframe would be a second thing to remember to cover — and a
       * class naming a keyframe nobody defined paints NOTHING, silently, which
       * has shipped in this tree four times.
       *
       * min-h so a two-line body and a four-line one do not move the dots.
       */}
      <div
        key={index}
        aria-roledescription="شريحة"
        aria-label={`${index + 1} من ${slides.length}`}
        className="banner-rise flex min-h-44 flex-col justify-end"
      >
        <p className="text-3xl font-extrabold leading-tight text-white">
          {slides[index].title}
        </p>
        {/* text-white/85, not a muted token: every `-muted` colour in the theme
            is tuned against the page surface, and none was checked against a
            photograph. */}
        <p className="mt-3 max-w-md leading-relaxed text-white/85">{slides[index].body}</p>

        {slides[index].href && (
          <Link
            href={slides[index].href}
            className="mt-4 w-fit rounded-full bg-white/15 px-5 py-2 text-sm font-semibold text-white backdrop-blur-sm transition hover:bg-white/25 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            {slides[index].linkLabel ?? "ابدأ الآن"}
          </Link>
        )}
      </div>

      {slides.length > 1 && (
        <ul className="mt-8 flex gap-2">
          {slides.map((slide, i) => (
            <li key={i}>
              <button
                // ⚠️ EXPLICIT: a bare button inside a form is a submit button,
                // and this panel sits on a page whose whole point is a form.
                type="button"
                onClick={() => setIndex(i)}
                aria-current={i === index}
                aria-label={`الشريحة ${i + 1}: ${slide.title}`}
                className={`h-2.5 rounded-full transition ${
                  i === index ? "w-8 bg-white" : "w-2.5 bg-white/40 hover:bg-white/70"
                }`}
              />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
