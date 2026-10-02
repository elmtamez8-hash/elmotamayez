import { STREAM_DEFAULTS } from "@/lib/whiteboard/page-model";

/**
 * Ready pens (spec 039 · US9, FR-031): Excalidraw's own freehand tool with a
 * width, an opacity and sometimes a colour preset — every one at or above the
 * stream's minimum stroke (FR-007).
 *
 * ponytail: chalk and spray are not here. Their texture is not something the
 * freehand tool can draw (FR-031 left them to the owner); a textured pen is a
 * custom renderer, and that is the upgrade if it is asked for.
 */

export type PenId = "marker" | "brush" | "highlighter";

export interface Pen {
  id: PenId;
  strokeWidth: number;
  /** 0–100, as Excalidraw counts it. */
  opacity: number;
  /** A fixed colour, or null to keep the colour the teacher chose. */
  colour: string | null;
}

export const PENS: Pen[] = [
  { id: "marker", strokeWidth: STREAM_DEFAULTS.strokeWidth, opacity: 100, colour: null },
  { id: "brush", strokeWidth: 8, opacity: 100, colour: null },
  { id: "highlighter", strokeWidth: 14, opacity: 35, colour: "#ffd60a" },
];
