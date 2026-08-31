"use client";

import Image from "next/image";
import Link from "next/link";
import type { ReactNode } from "react";

import { BrandMarkDecorative } from "@/components/ui/BrandMark";
import { SCRIM, TONE } from "@/components/ui/PageBanner";
import { usePlatformName } from "@/lib/platform-context";

/**
 * The frame every signed-out screen wears: the form on one side, a photograph on
 * the other.
 *
 * ⚠️ ONE COMPONENT, BECAUSE THERE WERE THREE COPIES OF ITS HEADER. Sign-in, the
 * two-factor challenge and registration each wrote the same eight lines — a mark,
 * a centred wrapper and a sentence — which is how `BrandMark`'s own docblock
 * describes the bug it was made to end, one layer up. The two-factor screen is
 * half of signing in and is easy to forget; it cannot be forgotten if there is
 * nothing to remember.
 *
 * ⚠️ THE PHOTOGRAPH IS THE SECOND CHILD AND CARRIES NO SIDE. In RTL that puts it
 * on the LEFT, which is what was asked for — but it is written as document order
 * inside a flex row, not as `left-*` or an `order-*` class, so an LTR rendering
 * of the same markup puts it on the right with no branch. Direction lives in the
 * document, never in a coordinate (spec 002).
 *
 * ⚠️ AND THE OVERLAY IS IMPORTED, NOT INVENTED. `SCRIM` and `TONE` come from
 * `PageBanner`, whose docblock records the measurement: white over the text band
 * lands between 6.9:1 and 8.7:1 against a PURE-WHITE photo pixel, the worst case
 * a crop can produce. A second pair of numbers written here would be a second
 * contrast bug waiting for a different photograph.
 *
 * The panel is `lg:` only. On a phone it would push the form below the fold, and
 * a sign-in screen whose first screenful is decoration is a sign-in screen that
 * asks the visitor to scroll before they can type.
 */
export function AuthShell({
  subtitle,
  children,
}: {
  /** The line under the mark: what this particular screen is for. */
  subtitle: string;
  children: ReactNode;
}) {
  const name = usePlatformName();

  return (
    <main id="main" className="flex min-h-screen">
      <div className="flex w-full flex-col justify-center px-4 py-10 lg:w-1/2">
        <div className="mx-auto w-full max-w-md">
          <div className="mb-8 text-center">
            {/*
             * ⚠️ THE MARK IS A WAY BACK. A visitor who arrives at the sign-in
             * screen by accident, or who wants to read the terms before typing a
             * password, had no exit at all: these screens carry no header and no
             * footer, so the logo was the only thing on them that LOOKS like it
             * leads somewhere, and it led nowhere.
             *
             * `BrandMarkDecorative`, not `BrandMark` — the link carries the
             * accessible name, and announcing the product twice is worse than
             * announcing it once (that component's own rule).
             *
             * `mx-auto flex w-fit` rather than `text-center` on the parent: the
             * mark is a BLOCK whose width comes from `aspect-ratio`, so it
             * ignores `text-align` — the defect that left the logo against the
             * inline-start edge of this very screen on 2026-08-31. `w-fit` also
             * keeps the focus ring around the mark instead of the whole row.
             */}
            <Link
              href="/"
              aria-label={`${name} — الصفحة الرئيسية`}
              className="mx-auto flex w-fit rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
            >
              <BrandMarkDecorative size="xl" />
            </Link>
            <p className="mt-2 text-ink-muted">{subtitle}</p>
          </div>

          {children}
        </div>
      </div>

      <div className="relative hidden lg:block lg:w-1/2">
        <Image
          src="/marketplace/hero-study.webp"
          // Atmosphere behind a form that already says what it is. Describing the
          // furniture is noise in a screen reader, not access.
          alt=""
          fill
          // Half the viewport at `lg` and hidden below it, so one hint covers
          // every breakpoint that renders it.
          sizes="50vw"
          className="object-cover"
        />

        {/* Scrim first, tone over it — the neutral layer carries the contrast and
            the colour only tints what is already dark enough. */}
        <div className={`absolute inset-0 bg-gradient-to-t ${SCRIM}`} aria-hidden="true" />
        <div className={`absolute inset-0 bg-gradient-to-t ${TONE}`} aria-hidden="true" />

        <div className="absolute inset-x-0 bottom-0 p-10">
          <p className="text-3xl font-extrabold leading-tight text-white">
            مدرّسك الخصوصي الموثوق،
            <br />
            أينما كنت في العالم العربي
          </p>
          {/* text-white/85, not a muted token: every `-muted` colour in the theme
              is tuned against the page surface, and none was checked against a
              photograph. */}
          <p className="mt-3 max-w-md leading-relaxed text-white/85">
            حصص فردية وجماعية، مباشرة ومسجّلة، مع مدرّسين يمرّون بمراجعة أكاديمية قبل
            انضمامهم.
          </p>
        </div>
      </div>
    </main>
  );
}
