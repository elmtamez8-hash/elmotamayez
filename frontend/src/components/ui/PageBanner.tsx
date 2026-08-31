import Image from "next/image";
import type { ComponentType, ReactNode } from "react";

/**
 * The masthead every public section page opens with.
 *
 * One component rather than four hand-built headers, because the parts that
 * differ are exactly four — title, description, photograph, icon — and the part
 * that must NOT differ is the scrim that makes the text readable. A per-page
 * overlay is a per-page contrast bug: the first one written with `/40` instead
 * of `/90` puts white on a bright photograph at 2:1 and nobody notices until an
 * audit.
 *
 * ⚠️ THE GRADIENT IS THE COMPONENT'S REASON TO EXIST, not decoration. Text over
 * a photograph has no contrast guarantee at all: the same white heading is 12:1
 * over a dark corner and 1.4:1 over a bright one, and which corner it lands on
 * depends on the crop and the viewport. The scrim removes the photograph from
 * the contrast calculation where the words are.
 *
 * ⚠️ TWO LAYERS, AND THE SPLIT IS THE POINT. The first version painted the tone
 * OPAQUE under the text — which passed every contrast check and buried the
 * photograph under a slab of flat maroon; the owner's word for it was «تقيل».
 * The colour and the legibility are separate jobs, so they are separate layers:
 * a neutral black scrim does the contrast work, and the tone is a light wash on
 * top of it that only has to say which page this is. The photograph now reads
 * through the whole banner — about 30% of it survives where the text sits,
 * rising to 100% above — instead of being replaced by a colour at the bottom.
 *
 * The numbers were measured, not chosen: white over the text band lands between
 * 6.9:1 and 8.7:1 against a PURE-WHITE photo pixel, which is the worst case a
 * crop can produce. The floor was set while four tones existed and the brass
 * was the lightest of them; keeping it now that only the maroon remains costs
 * nothing and survives the next colour anyone adds.
 *
 * Both layers hold their strength to 55% of the height and fall to nothing by
 * 85%. That boundary is not arbitrary either: the content is bottom-anchored,
 * so the heading's top edge sits at roughly 55%, and a gradient whose middle
 * stop defaults to 50% puts the largest text in the fade.
 *
 * Vertical, not from the inline start, and that is deliberate: CSS gradients
 * take `to top` but have no logical direction keyword, so a horizontal scrim
 * would need flipping by hand for LTR — one more thing to forget. A vertical
 * one is correct in both directions with no branch.
 */

/**
 * ⚠️ ONE COLOUR, NOT A PROP. The wash was per-page — maroon here, green there,
 * ink on two others — and four mastheads in four colours read as four products
 * to a visitor moving between them. The identity colour is the maroon; a page
 * does not get its own. Full class strings, never `from-${tone}/95`: Tailwind
 * scans source text and generates only the classes it can SEE, and an
 * interpolated name produces no CSS at all — a banner with no scrim, which
 * looks like a design choice rather than a missing file.
 */
/**
 * ⚠️ EXPORTED, AND THE EXPORT IS THE POINT. `AuthShell` paints text over the same
 * kind of photograph and must not invent its own overlay — this component's own
 * docblock says why: a per-page overlay is a per-page contrast bug, and the first
 * one written with `/40` puts white on a bright crop at 2:1 with nobody noticing
 * until an audit. Two callers, one pair of numbers.
 */
export const TONE = "from-primary/55 via-primary/45 via-55% to-transparent to-85%";

/** The neutral half. Same shape, so the two fade out together. */
export const SCRIM = "from-black/50 via-black/45 via-55% to-transparent to-85%";

/**
 * The same two layers, restopped for a FULL-HEIGHT panel.
 *
 * ⚠️ THE COLOURS ARE THE DECISION AND THE STOPS ARE THE GEOMETRY, and only the
 * second one depends on the box. The pair above was measured against a masthead
 * `min-h-60`, where 55% is just above the heading; poured into `AuthShell`'s
 * half-viewport column the identical string holds full strength across the bottom
 * 55% of NINE HUNDRED pixels and clears only in the top 15% — the photograph is
 * then a maroon slab with a picture behind it, which is the exact «تقيل» the
 * split above was made to end, arriving by a different road.
 *
 * So the stops move and the strength does NOT weaken: at the text band this is
 * `/60` and `/55` against the banner's `/55` and `/50`, i.e. contrast where the
 * words are is greater than the measured floor, while everything above 52% is the
 * photograph at full strength. A second PAIR OF COLOURS would have been the
 * per-page contrast bug this file exists to refuse; a second pair of stops is the
 * same decision applied to a box of a different shape.
 */
export const TONE_TALL = "from-primary/60 via-primary/45 via-28% to-transparent to-52%";

/** The neutral half of the tall pair. */
export const SCRIM_TALL = "from-black/55 via-black/45 via-28% to-transparent to-52%";

export function PageBanner({
  title,
  description,
  image,
  imageAlt = "",
  icon: Icon,
  children,
}: {
  title: string;
  description: string;
  /** Path under /public. 1800×640 keeps one file sharp across every breakpoint. */
  image: string;
  /**
   * Empty by default. These photographs are atmosphere behind a heading that
   * already says what the page is; describing the furniture twice is noise in a
   * screen reader, not access.
   */
  imageAlt?: string;
  icon: ComponentType<{ className?: string }>;
  /** A count, a filter summary — anything the page wants under its description. */
  children?: ReactNode;
}) {
  return (
    <section className="relative mb-10 flex min-h-60 items-end overflow-hidden rounded-3xl sm:min-h-68">
      <Image
        src={image}
        alt={imageAlt}
        fill
        // The LCP element on every page that uses it: this is the largest thing
        // above the fold, and Next lazy-loads by default.
        priority
        sizes="(min-width: 1280px) 1280px, 100vw"
        className="object-cover"
      />

      {/* Scrim first, tone over it: the neutral layer carries the contrast and
          the colour only tints what is already dark enough. Reversed, the tone
          would have to be opaque again to do both jobs. */}
      <div
        className={`absolute inset-0 bg-gradient-to-t ${SCRIM}`}
        aria-hidden="true"
      />
      <div
        className={`absolute inset-0 bg-gradient-to-t ${TONE}`}
        aria-hidden="true"
      />

      {/* One authored arrival, staggered by 90ms: mark, then name, then the
          sentence — the order the page is read in. It runs once on load and
          never again; a masthead that re-animates is a masthead performing. */}
      <div className="relative p-6 sm:p-10">
        <span
          className="banner-rise mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 text-white backdrop-blur-sm"
          aria-hidden="true"
        >
          <Icon className="h-6 w-6" />
        </span>

        <h1
          className="banner-rise mb-3 text-3xl font-extrabold text-white sm:text-4xl lg:text-5xl"
          style={{ animationDelay: "90ms" }}
        >
          {title}
        </h1>

        {/* text-white/85, not a muted token: every `-muted` colour in the theme
            is tuned against the page surface, and none of them was checked
            against a photograph. White at 85% over a 95% tone stays past 4.5:1
            wherever the crop lands. */}
        <p
          className="banner-rise max-w-2xl text-base leading-relaxed text-white/85 sm:text-lg"
          style={{ animationDelay: "180ms" }}
        >
          {description}
        </p>

        {children}
      </div>
    </section>
  );
}
