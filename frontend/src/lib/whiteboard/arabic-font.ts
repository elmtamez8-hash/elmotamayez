/**
 * Arabic glyphs for Excalidraw, which ships none (0.18.1: no bundled family covers U+0600…).
 *
 * Excalidraw's font registry is private, but every family's canvas font string ends in the
 * same fallback — `…, Segoe UI Emoji` (FONT_FAMILY_FALLBACKS) — and that name is registered
 * as `local:` only, so the library never creates a FontFace for it and never touches it.
 * We register two faces under that exact name:
 *
 *   1. Cairo (the app's own Arabic face, OFL) limited to the Arabic unicode-range, so it
 *      only ever answers for codepoints every Excalidraw family lacks;
 *   2. the installed emoji font again, with NO range — an author @font-face of a family
 *      name hides the installed font of the same name (CSS Fonts 4), so without this
 *      second rule Windows would lose its colour emoji on the board.
 *
 * Phase 0 checks this in Chrome, Edge and Firefox (tasks T005, T011). If it fails, the
 * fallback is a minimal patch to Excalidraw, which needs the owner's approval.
 */

export const ARABIC_FONT_URL = "/whiteboard/fonts/cairo-arabic.woff2";

/** The family name Excalidraw already falls back to for every text element. */
export const FALLBACK_FAMILY = "Segoe UI Emoji";

/** Google Fonts' own range for Cairo's Arabic subset (the file is that subset). */
const ARABIC_RANGE =
  "U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC";

const STYLE_ID = "whiteboard-arabic-font";

/**
 * ORDER MATTERS: among faces of one family the browser tries the LAST defined first
 * (CSS Fonts 4 §5.1). The emoji face has no range, so defined after Cairo it claimed the
 * Arabic codepoints too and they fell to the system serif — measured in the lab on an
 * exported SVG. Cairo, with its Arabic range, is therefore defined last.
 */
function fontFaceCss(src: string): string {
  return [
    `@font-face { font-family: "${FALLBACK_FAMILY}"; src: local("Segoe UI Emoji"), local("SegoeUIEmoji"); }`,
    `@font-face { font-family: "${FALLBACK_FAMILY}"; src: ${src} format("woff2");`,
    `  font-weight: 100 1000; font-display: block; unicode-range: ${ARABIC_RANGE}; }`,
  ].join("\n");
}

/**
 * Registers the faces and waits until the Arabic one is usable. Call BEFORE mounting
 * Excalidraw: text measured before the face loads keeps the wrong width.
 */
export async function ensureArabicFont(): Promise<void> {
  if (!document.getElementById(STYLE_ID)) {
    const style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = fontFaceCss(`url("${ARABIC_FONT_URL}")`);
    document.head.appendChild(style);
  }
  await document.fonts.load(`16px "${FALLBACK_FAMILY}"`, "ب");
}

function dataUrl(url: string): Promise<string> {
  return fetch(url)
    .then((response) => response.blob())
    .then(
      (blob) =>
        new Promise<string>((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(String(reader.result));
          reader.onerror = () => reject(reader.error);
          reader.readAsDataURL(blob);
        }),
    );
}

/**
 * Excalifont's basic Latin face — letters, digits, punctuation — the part of
 * Excalidraw's own font that mixed Arabic/English text uses («H₂O»، «100»). File
 * name and range copied from Excalidraw 0.18.1's `ExcalifontFontFaces`
 * (dist/dev/chunk-4FTI6OG3.js); the package is pinned, and `copy-excalidraw-fonts`
 * puts the file in `public/excalidraw/fonts/`. Owner decision on phase 0: the
 * exported SVG must match the screen for Latin too.
 */
const EXCALIFONT_LATIN_URL = "/excalidraw/fonts/Excalifont/Excalifont-Regular-a88b72a24fb54c9f94e3b5fdaa7481c9.woff2";
const EXCALIFONT_LATIN_RANGE =
  "U+20-7e,U+a0-a3,U+a5-a6,U+a8-ab,U+ad-b1,U+b4,U+b6-b8,U+ba-ff,U+131,U+152-153,U+2bc,U+2c6,U+2da,U+2dc,U+304,U+308,U+2013-2014,U+2018-201a,U+201c-201e,U+2020,U+2022,U+2024-2026,U+2030,U+2039-203a,U+20ac,U+2122,U+2212";

let embeddedCss: Promise<string> | null = null;

/** The faces with their fonts inlined, for a file that must render anywhere. */
function embeddedFontCss(): Promise<string> {
  embeddedCss ??= Promise.all([dataUrl(ARABIC_FONT_URL), dataUrl(EXCALIFONT_LATIN_URL)]).then(
    ([arabic, latin]) =>
      [
        fontFaceCss(`url("${arabic}")`),
        `@font-face { font-family: "Excalifont"; src: url("${latin}") format("woff2"); unicode-range: ${EXCALIFONT_LATIN_RANGE}; }`,
      ].join("\n"),
  );
  return embeddedCss;
}

/**
 * Excalidraw's SVG writer gives right-aligned RTL text `text-anchor="end"` at x = width
 * (chunk-4FTI6OG3.js, renderer: `textAlign === "right" || direction === "rtl" ? "end"`).
 * In an RTL run `end` is the LEFT edge, so the line starts at the box's right edge and
 * runs out of it. `start` is the right edge in RTL, which is what right-aligned means.
 * Left-aligned RTL (x = 0, `end`) is already correct and is left alone.
 */
export function fixRtlTextAnchors(svg: SVGSVGElement): void {
  for (const text of svg.querySelectorAll("text[direction='rtl'][text-anchor='end']")) {
    if (text.getAttribute("x") !== "0") text.setAttribute("text-anchor", "start");
  }
}

/** Adds the Arabic face and Excalifont's Latin face to an exported SVG (export with `skipInliningFonts: true`). */
export async function injectFontsIntoSvg(svg: SVGSVGElement): Promise<SVGSVGElement> {
  const ns = "http://www.w3.org/2000/svg";
  let defs = svg.querySelector("defs");
  if (!defs) {
    defs = document.createElementNS(ns, "defs");
    svg.insertBefore(defs, svg.firstChild);
  }
  const style = document.createElementNS(ns, "style");
  style.textContent = await embeddedFontCss();
  defs.appendChild(style);
  fixRtlTextAnchors(svg);
  return svg;
}
