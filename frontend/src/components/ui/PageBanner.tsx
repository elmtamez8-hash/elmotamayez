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
 * depends on the crop and the viewport. The scrim below removes the photograph
 * from the contrast calculation where the words are — under the text the tone is
 * opaque, so the effective background is the brand colour and the pairing is the
 * one already verified in `globals.css` (white on maroon, 9.4:1).
 *
 * Vertical, not from the inline start, and that is deliberate: CSS gradients
 * take `to top` but have no logical direction keyword, so a horizontal scrim
 * would need flipping by hand for LTR — one more thing to forget. A vertical
 * one is correct in both directions with no branch.
 *
 * ⚠️ THE STOP POSITIONS ARE THE FIX, not the opacities. A default three-stop
 * gradient puts its middle stop at 50% height — but the content is bottom-
 * anchored, so the heading sits at roughly 60% and was landing in the fade,
 * around 55% tone, where white over a bright photograph drops under 4.5:1.
 * Holding full tone to 40% and 80% to 75% keeps the whole text block above the
 * bar (7.6:1 worst case, measured against a pure-white pixel) while still
 * letting the photograph read at the top.
 */

/**
 * ⚠️ Full class strings, never `from-${tone}/95`. Tailwind scans source text and
 * generates only the classes it can SEE; an interpolated name produces no CSS
 * at all, and the failure is a banner with no scrim — which looks like a design
 * choice rather than a missing file.
 */
const TONES = {
  primary: "from-primary from-40% via-primary/80 via-75% to-primary/15",
  secondary: "from-secondary from-40% via-secondary/80 via-75% to-secondary/15",
  accent: "from-accent from-40% via-accent/80 via-75% to-accent/15",
  ink: "from-ink from-40% via-ink/80 via-75% to-ink/15",
} as const;

export function PageBanner({
  title,
  description,
  image,
  imageAlt = "",
  icon: Icon,
  tone = "primary",
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
  tone?: keyof typeof TONES;
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

      <div
        className={`absolute inset-0 bg-gradient-to-t ${TONES[tone]}`}
        aria-hidden="true"
      />

      <div className="relative p-6 sm:p-10">
        <span
          className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 text-white backdrop-blur-sm"
          aria-hidden="true"
        >
          <Icon className="h-6 w-6" />
        </span>

        <h1 className="mb-3 text-3xl font-extrabold text-white sm:text-4xl lg:text-5xl">
          {title}
        </h1>

        {/* text-white/85, not a muted token: every `-muted` colour in the theme
            is tuned against the page surface, and none of them was checked
            against a photograph. White at 85% over a 95% tone stays past 4.5:1
            wherever the crop lands. */}
        <p className="max-w-2xl text-base leading-relaxed text-white/85 sm:text-lg">
          {description}
        </p>

        {children}
      </div>
    </section>
  );
}
