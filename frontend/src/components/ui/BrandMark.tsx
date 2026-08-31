"use client";

import { usePlatformName } from "@/lib/platform-context";

const SIZES = {
  sm: "h-8",
  md: "h-9",
  lg: "h-10",
  xl: "h-12",
} as const;

type Size = keyof typeof SIZES;

/**
 * ⚠️ `mx-auto`, AND `text-center` ON THE PARENT DOES NOT DO IT. `.wordmark` is
 * `display: block` with its width derived from `aspect-ratio` — so it is a BLOCK
 * whose width is definite, and a block ignores its parent's `text-align`. The
 * three auth screens each wrap this in `<div className="mb-8 text-center">`,
 * which centred the `<h1>` TEXT that used to be here and silently stopped
 * centring anything the moment the text became a mark: the logo sat against the
 * inline-start edge — the RIGHT edge in RTL — on the sign-in page. Reported by
 * the owner, 2026-08-31.
 *
 * A prop rather than a `className`: `components/ui/` takes a closed set of
 * variants, so «centred» is a state this component knows about rather than a
 * class four call sites each have to remember.
 */
function classes(size: Size, centered: boolean): string {
  return `wordmark ${SIZES[size]}${centered ? " mx-auto" : ""}`;
}

/**
 * The product's logo, wherever it stands for the product's name.
 *
 * ⚠️ ONE SPELLING, BECAUSE THE MARK NOW APPEARS IN SIX PLACES. The panel's
 * sidebar, the public header, the footer, and the login, registration and
 * invitation screens each used to write the name as text — and the three auth
 * screens wrote it as `<h1 className="text-3xl font-bold text-primary-ink">`,
 * three copies of one decision. A seventh caller that reached for `.wordmark`
 * directly would be free to forget the accessible name.
 *
 * ⚠️ AND THE ACCESSIBLE NAME IS NOT OPTIONAL. `.wordmark` is a `mask-image` over
 * a background colour on an EMPTY span, so there is no text node at all: without
 * `aria-label` a screen reader announces nothing where the product's name used to
 * be, and the link that usually wraps it is announced as «رابط». The name comes
 * from the settings row, so the panel, the site and the reader all say the same
 * thing.
 *
 * ⚠️ AND IT PAINTS FROM ONE FILE FOR BOTH THEMES — the mask carries the shape and
 * `background-color` carries the brand, maroon on light and warm white on dark.
 * A second exported image would be a second thing to keep in step.
 *
 * `role="img"` rather than a bare span: an element whose only content is a
 * background image is invisible to the accessibility tree without it.
 */
export function BrandMark({
  size = "md",
  centered = false,
}: {
  size?: Size;
  centered?: boolean;
}) {
  return <span className={classes(size, centered)} role="img" aria-label={usePlatformName()} />;
}

/**
 * The same mark inside something that already carries the name — a link with its
 * own `aria-label`, for instance. Announced twice is worse than announced once.
 */
export function BrandMarkDecorative({
  size = "md",
  centered = false,
}: {
  size?: Size;
  centered?: boolean;
}) {
  return <span className={classes(size, centered)} aria-hidden="true" />;
}
