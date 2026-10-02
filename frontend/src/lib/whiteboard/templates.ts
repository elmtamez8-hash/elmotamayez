import type { TemplateName } from "@/lib/whiteboard/custom-data";
import { PAGE_HEIGHT, PAGE_WIDTH } from "@/lib/whiteboard/page-model";

export type { TemplateName };

/**
 * Page backgrounds (spec 039 · US9, FR-030): ruled, grid, dotted, isometric,
 * graph paper and Arabic copybook lines.
 *
 * ⚠️ NOTHING IS UPLOADED, like the stickers: the picture's file id is a fixed
 * `template:<name>:v1` — accepted by the server with no lookup, never stored —
 * and it is drawn here from its name on every device. The page keeps one locked
 * image element under everything else, so the template is SAVED and EXPORTED
 * with the page.
 *
 * One drawing serves all three boards: half-transparent grey reads on white, on
 * the blackboard and on the green board alike.
 */


export const TEMPLATES: TemplateName[] = ["lined", "grid", "dotted", "isometric", "graph", "arabic-lines"];

const LINE = "rgba(128,128,128,0.55)";
const STRONG = "rgba(128,128,128,0.85)";
const BASELINE = "rgba(214,40,40,0.7)";

export function templateFileId(name: TemplateName): string {
  return `template:${name}:v1`;
}

/** The template a file id names, or null. */
export function templateOf(fileId: string): TemplateName | null {
  const match = /^template:([a-z-]+):v1$/.exec(fileId);
  return match && (TEMPLATES as string[]).includes(match[1]) ? (match[1] as TemplateName) : null;
}

type Line = [number, number, number, number, string, number];

/**
 * The template's strokes, in page units (1920 × 1080). Pure, so the spacing is
 * tested without a canvas: every line inside the page, none crowded below the
 * stream's legibility.
 */
export function templateLines(name: TemplateName): { lines: Line[]; dots: [number, number][] } {
  const lines: Line[] = [];
  const dots: [number, number][] = [];
  const W = PAGE_WIDTH;
  const H = PAGE_HEIGHT;

  switch (name) {
    // ⚠️ EDGE TO EDGE, and centred top to bottom: margins inside the template read
    // as «the background does not fill the page» once the teacher zooms (owner,
    // 2026-10-02). The page frame is the margin.
    case "lined": {
      const step = 72;
      const count = Math.floor(H / step);
      const first = (H - (count - 1) * step) / 2;
      for (let i = 0; i < count; i++) lines.push([0, first + i * step, W, first + i * step, LINE, 2]);
      break;
    }
    case "grid":
      for (let x = 0; x <= W; x += 60) lines.push([x, 0, x, H, LINE, 1.5]);
      for (let y = 0; y <= H; y += 60) lines.push([0, y, W, y, LINE, 1.5]);
      break;
    case "graph":
      // Fine squares every 24, a heavier line every five.
      for (let i = 0, x = 0; x <= W; x += 24, i++) lines.push([x, 0, x, H, i % 5 === 0 ? STRONG : LINE, i % 5 === 0 ? 2 : 1]);
      for (let i = 0, y = 0; y <= H; y += 24, i++) lines.push([0, y, W, y, i % 5 === 0 ? STRONG : LINE, i % 5 === 0 ? 2 : 1]);
      break;
    case "dotted":
      for (let x = 24; x < W; x += 48) for (let y = 24; y < H; y += 48) dots.push([x, y]);
      break;
    case "isometric": {
      // Triangles: horizontal-free lattice at ±30°, the usual isometric paper.
      const step = 60;
      const slope = Math.tan(Math.PI / 6);
      for (let c = -H; c < W + H; c += step / Math.cos(Math.PI / 6)) {
        lines.push([c, 0, c + H / slope, H, LINE, 1.5]);
        lines.push([c, H, c + H / slope, 0, LINE, 1.5]);
      }
      for (let x = 0; x <= W; x += step) lines.push([x, 0, x, H, LINE, 1]);
      break;
    }
    case "arabic-lines": {
      // The copybook band: a top line, a mid line and a red baseline, then a gap.
      const band = 120;
      const gap = 50;
      const count = Math.floor((H + gap) / (band + gap));
      const first = (H - (count * (band + gap) - gap)) / 2;
      for (let i = 0; i < count; i++) {
        const top = first + i * (band + gap);
        lines.push([0, top, W, top, LINE, 1.5]);
        lines.push([0, top + band / 2, W, top + band / 2, LINE, 1.5]);
        lines.push([0, top + band, W, top + band, BASELINE, 3]);
      }
      break;
    }
  }

  return { lines, dots };
}

/** The template as a page-sized PNG. */
export async function renderTemplate(name: TemplateName): Promise<Blob> {
  const canvas = document.createElement("canvas");
  canvas.width = PAGE_WIDTH;
  canvas.height = PAGE_HEIGHT;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("no canvas");

  const { lines, dots } = templateLines(name);
  ctx.lineCap = "round";
  for (const [x1, y1, x2, y2, colour, width] of lines) {
    ctx.strokeStyle = colour;
    ctx.lineWidth = width;
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();
  }
  ctx.fillStyle = STRONG;
  for (const [x, y] of dots) {
    ctx.beginPath();
    ctx.arc(x, y, 3, 0, Math.PI * 2);
    ctx.fill();
  }

  return new Promise<Blob>((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("no blob"))), "image/png"));
}
