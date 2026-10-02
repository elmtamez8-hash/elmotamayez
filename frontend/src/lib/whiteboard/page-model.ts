/**
 * The board's page: a fixed 16:9 frame, fitted to the window, with streaming defaults.
 *
 * Pure functions only — no Excalidraw import — so the numbers are testable in jsdom and
 * `excalidraw-api.ts` stays the one file that talks to the library.
 *
 * Why the zoom is computed here and not with `scrollToContent(frame, { fitToContent })`:
 * in 0.18.1 that call caps the zoom at 1 and floors it to a step of 0.1, so on a
 * 1366×768 laptop the frame fills ~70% of the width and the student sees a border of
 * empty canvas around every page. The student watches the TAB, not an export, so
 * "teacher's view = what students see" only holds when the frame fills the window.
 */

export const PAGE_WIDTH = 1920;
export const PAGE_HEIGHT = 1080;

/** Excalidraw's own zoom bounds (packages/excalidraw/constants: MIN_ZOOM 0.1, MAX_ZOOM 30). */
const MIN_ZOOM = 0.1;
const MAX_ZOOM = 30;

export type BoardBackground = "white" | "blackboard" | "greenboard";

/** Each page's frame has its OWN id: a shared id lets undo history apply page A's frame edits to page B. */
export function pageFrameId(pageUuid: string): string {
  return `frame:${pageUuid}`;
}

export interface Viewport {
  zoom: number;
  scrollX: number;
  scrollY: number;
}

/**
 * The zoom and scroll that make the 1920×1080 frame at scene (0,0) fill the window,
 * centred, letter-boxed on the long side.
 *
 * Excalidraw maps scene → viewport as `(sceneX + scrollX) * zoom`, so centring the
 * frame's middle (960, 540) on the window's middle gives the scroll below.
 */
/**
 * The view that fills the canvas with ONE SCREEN of the page: a page grows
 * downward in screens of PAGE_WIDTH × PAGE_HEIGHT (owner, 2026-10-02: «I move down
 * to keep explaining in empty space»), and the class always sees one screen.
 */
export function fitViewport(viewportWidth: number, viewportHeight: number, screen = 0): Viewport {
  const raw = Math.min(viewportWidth / PAGE_WIDTH, viewportHeight / PAGE_HEIGHT);
  const zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, raw));

  return {
    zoom,
    scrollX: viewportWidth / (2 * zoom) - PAGE_WIDTH / 2,
    scrollY: viewportHeight / (2 * zoom) - (screen * PAGE_HEIGHT + PAGE_HEIGHT / 2),
  };
}

/** How many screens a page of this height holds (at least one). */
export function screensIn(pageHeight: number): number {
  return Math.max(1, Math.round(pageHeight / PAGE_HEIGHT));
}

/**
 * The screen nearest the middle of the canvas — where the teacher is now — from
 * Excalidraw's own scroll: the page point at the centre is `height / (2·zoom) − scrollY`.
 */
export function screenAt(scrollY: number, zoom: number, viewportHeight: number, screens: number): number {
  const centre = viewportHeight / (2 * zoom) - scrollY;
  return Math.min(screens - 1, Math.max(0, Math.round((centre - PAGE_HEIGHT / 2) / PAGE_HEIGHT)));
}

/**
 * Streaming defaults — PROVISIONAL until Phase 0 measures them on a low-end Android phone.
 *
 * The page is 1920 px wide and reaches a phone held sideways at roughly a third of that,
 * so a 36 px letter arrives near 12 px: the smallest size that stays readable after video
 * compression. Excalidraw's own sizes are 16 / 20 / 28 / 36; 28 is the floor offered.
 */
export const STREAM_DEFAULTS = {
  fontSize: 36,
  minFontSize: 28,
  /** Excalidraw's stroke widths are 1 / 2 / 4; 1 vanishes in a compressed stream. */
  strokeWidth: 4,
  minStrokeWidth: 2,
} as const;

export interface BackgroundStyle {
  canvas: string;
  /** The pen colour the board starts with on this background. */
  pen: string;
  /** A short high-contrast palette offered on this background, pen first. */
  palette: readonly string[];
}

export const BACKGROUNDS: Record<BoardBackground, BackgroundStyle> = {
  white: { canvas: "#ffffff", pen: "#1e1e1e", palette: ["#1e1e1e", "#a51e1e", "#0b4a8b", "#1b5e20", "#6a1b9a"] },
  blackboard: { canvas: "#1c2024", pen: "#f8f9fa", palette: ["#f8f9fa", "#ffe066", "#8ce99a", "#74c0fc", "#ffa8a8"] },
  greenboard: { canvas: "#1e3d2f", pen: "#fffbe6", palette: ["#fffbe6", "#ffe066", "#ffc9c9", "#a5d8ff", "#ffffff"] },
};

/** WCAG relative luminance of a #rrggbb colour. */
function luminance(hex: string): number {
  const channel = (i: number) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

/** WCAG contrast ratio between two #rrggbb colours (1 … 21). */
export function contrastRatio(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

/** The pen a board starts with on a background (US1-3). */
export function penFor(background: BoardBackground): string {
  return BACKGROUNDS[background].pen;
}

/** Just the colour fields recolouring reads and writes — any Excalidraw element has them. */
export interface Colourable {
  strokeColor: string;
  backgroundColor: string;
}

/**
 * Owner decision on phase 0 (2026-10-02): switching a board's background RECOLOURS
 * what is already drawn, or dark ink vanishes on the blackboard (measured in the lab).
 * Each colour of the old palette becomes the colour in the same position of the new
 * one; every other colour — a photo's frame, a teacher's custom pick, `transparent`
 * — stays exactly as it was. Returns only the elements that changed.
 */
export function recolorForBackground<T extends Colourable>(
  elements: readonly T[],
  from: BoardBackground,
  to: BoardBackground,
): T[] {
  if (from === to) return [];

  const source = BACKGROUNDS[from].palette.map((colour) => colour.toLowerCase());
  const target = BACKGROUNDS[to].palette;
  const swap = (colour: string) => {
    const index = source.indexOf(colour.toLowerCase());
    return index === -1 ? colour : target[index];
  };

  const changed: T[] = [];
  for (const element of elements) {
    const strokeColor = swap(element.strokeColor);
    const backgroundColor = swap(element.backgroundColor);
    if (strokeColor !== element.strokeColor || backgroundColor !== element.backgroundColor) {
      changed.push({ ...element, strokeColor, backgroundColor });
    }
  }

  return changed;
}
