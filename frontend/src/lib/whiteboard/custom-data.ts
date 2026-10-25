/**
 * The whiteboard's own data on Excalidraw elements (`element.customData`), each
 * shape versioned so a later release can migrate an older board (FR-028).
 *
 * ⚠️ AN UNKNOWN KIND IS KEPT, NEVER DROPPED. A newer release (or, later, another
 * participant in a collaboration session) may write a kind this code does not
 * know; dropping it would delete that person's work the first time an older tab
 * saved the page. It stays exactly as it arrived — an ordinary image on screen.
 */

export type TemplateName = "lined" | "grid" | "dotted" | "isometric" | "graph" | "arabic-lines" | "music";

export type WbCustomData =
  | { kind: "frame"; v: 1; template?: TemplateName }
  | { kind: "doc-background"; v: 1; importUuid: string; page: number }
  | { kind: "template"; v: 1; name: TemplateName }
  | {
      kind: "table";
      v: 1;
      dir: "rtl" | "ltr";
      // `span` [rows, cols] on a merged cell's first one; the cells it covers stay in
      // the grid, empty. Optional, so every v1 table already saved reads the same.
      rows: { cells: { text: string; fill?: string; span?: [number, number] }[] }[];
      colWidths: number[];
    }
  | { kind: "math"; v: 1; latex: string; display: boolean }
  | {
      kind: "graph";
      v: 1;
      // Each function as typed (mathjs syntax, «y =» allowed); `y` null = worked out from the curves.
      functions: { expr: string; color: string }[];
      x: [number, number];
      y: [number, number] | null;
      points: { x: number; y: number; label: string }[];
      angle: "deg" | "rad";
    }
  | { kind: "quran"; v: 1; surah: number; from: number; to: number; edition: "tanzil-uthmani-1.1" }
  | { kind: "sticker"; v: 1; name: string };
