import { FALLBACK_FAMILY } from "@/lib/whiteboard/arabic-font";
import type { WbCustomData } from "@/lib/whiteboard/custom-data";

/**
 * A table on the board (story 6): its cells are the source (`customData`), the
 * picture is drawn from them — so «تعديل» reopens the same cells, and the
 * element keeps its id, place and size (the rich-object pattern, ADR-001).
 */
export type TableData = Extract<WbCustomData, { kind: "table" }>;
export type TableCell = TableData["rows"][number]["cells"][number];

/** Light fills that stay readable under black text in a compressed stream. */
export const TABLE_FILLS = ["#fff3b0", "#c8f2c2", "#cfe3ff", "#ffd6d6", "#e6e6e6"] as const;

const COLUMN = 240;
const FONT_SIZE = 30;
const LINE = 40;
const PAD = 14;
/** Drawn at up to twice the board's units (crisp when zoomed), never past a picture's largest side. */
const SCALE = 2;
const MAX_SIDE = 2560;
/** A pasted sheet is cut here: a table past it is not readable on a stream anyway. */
export const MAX_ROWS = 50;
export const MAX_COLS = 12;

export function blankTable(rows = 3, cols = 3): TableData {
  return {
    kind: "table",
    v: 1,
    dir: "rtl",
    rows: Array.from({ length: rows }, () => ({ cells: Array.from({ length: cols }, () => ({ text: "" })) })),
    colWidths: Array.from({ length: cols }, () => COLUMN),
  };
}

/** Every row as long as the longest, every column with a width. */
export function normalise(data: TableData): TableData {
  const cols = Math.max(1, data.colWidths.length, ...data.rows.map((row) => row.cells.length));
  return {
    ...data,
    rows: data.rows.map((row) => ({
      cells: Array.from({ length: cols }, (_, i) => {
        const cell = row.cells[i] ?? { text: "" };
        // Only our own fills: a scene could carry anything, and it reaches `style`.
        return cell.fill && !(TABLE_FILLS as readonly string[]).includes(cell.fill) ? { text: cell.text } : cell;
      }),
    })),
    colWidths: Array.from({ length: cols }, (_, i) => data.colWidths[i] ?? COLUMN),
  };
}

/**
 * A table copied from Excel or Google Sheets: the HTML `<table>` when there is
 * one, else tab-separated text. Read with `DOMParser` and `textContent` only —
 * nothing from the clipboard is ever put into the page as HTML. Null when the
 * clipboard holds no table of at least two cells (one cell is plain text).
 */
export function parseClipboardTable(html: string, text: string): TableData | null {
  let grid: string[][] = [];
  // A tab grid in the plain text: two lines or more, every line with a tab.
  const textLines = text.replace(/\r\n?/g, "\n").replace(/\n$/, "").split("\n");
  const tabGrid = textLines.length > 1 && textLines.every((line) => line.includes("\t"));
  // A spreadsheet says so; a layout table in an email or a web page does not.
  const fromSheet = /google-sheets-html-origin|urn:schemas-microsoft-com:office:excel|Excel\.Sheet/i.test(html);
  if (html.includes("<table") && (fromSheet || tabGrid)) {
    const doc = new DOMParser().parseFromString(html, "text/html");
    grid = [...doc.querySelectorAll("table tr")].map((tr) =>
      [...tr.querySelectorAll("td, th")].map((cell) => (cell.textContent ?? "").replace(/\s+/g, " ").trim()),
    );
  } else if (!html && tabGrid) {
    // Tabs alone only without rich text: a Word list or indented code carries both.
    grid = textLines.map((line) => line.split("\t").map((cell) => cell.trim()));
  }
  grid = grid.filter((row) => row.length > 0).slice(0, MAX_ROWS).map((row) => row.slice(0, MAX_COLS));
  if (grid.flat().filter((cell) => cell !== "").length < 2) return null;

  return normalise({
    ...blankTable(0, 0),
    rows: grid.map((row) => ({ cells: row.map((value) => ({ text: value })) })),
    colWidths: [],
  });
}

function lines(ctx: CanvasRenderingContext2D, text: string, width: number): string[] {
  const out: string[] = [];
  for (const paragraph of text.split("\n")) {
    let line = "";
    for (const word of paragraph.split(" ")) {
      const next = line ? `${line} ${word}` : word;
      if (line && ctx.measureText(next).width > width) {
        out.push(line);
        line = word;
      } else {
        line = next;
      }
    }
    out.push(line);
  }
  return out;
}

/**
 * The table's picture, PNG. Right to left: the first column is on the RIGHT,
 * as an Arabic reader reads it (US6-1). Returns the size in the board's units.
 */
export async function renderTable(input: TableData): Promise<{ blob: Blob; width: number; height: number }> {
  const data = normalise(input);
  const font = `${FONT_SIZE}px "${FALLBACK_FAMILY}", sans-serif`;
  await document.fonts?.load(font, data.rows.map((row) => row.cells.map((cell) => cell.text).join(" ")).join(" ") || "ب").catch(() => undefined);

  const measure = document.createElement("canvas").getContext("2d");
  if (!measure) throw new Error("no canvas");
  measure.font = font;
  const wrapped = data.rows.map((row) => row.cells.map((cell, i) => lines(measure, cell.text, data.colWidths[i] - PAD * 2)));
  const heights = wrapped.map((row) => Math.max(1, ...row.map((cell) => cell.length)) * LINE + PAD * 2);
  const width = data.colWidths.reduce((sum, w) => sum + w, 0);
  const height = heights.reduce((sum, h) => sum + h, 0);

  const scale = Math.min(SCALE, MAX_SIDE / Math.max(width, height));
  const canvas = document.createElement("canvas");
  canvas.width = Math.ceil(width * scale);
  canvas.height = Math.ceil(height * scale);
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("no canvas");
  ctx.scale(scale, scale);
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, width, height);
  ctx.font = font;
  ctx.textBaseline = "middle";
  ctx.direction = data.dir;
  ctx.textAlign = data.dir === "rtl" ? "right" : "left";

  // Column i's left edge: from the right for RTL.
  const lefts: number[] = [];
  let edge = data.dir === "rtl" ? width : 0;
  for (const w of data.colWidths) {
    if (data.dir === "rtl") edge -= w;
    lefts.push(edge);
    if (data.dir === "ltr") edge += w;
  }

  let top = 0;
  data.rows.forEach((row, r) => {
    row.cells.forEach((cell, c) => {
      const left = lefts[c];
      const w = data.colWidths[c];
      if (cell.fill) {
        ctx.fillStyle = cell.fill;
        ctx.fillRect(left, top, w, heights[r]);
      }
      ctx.fillStyle = "#111111";
      const x = data.dir === "rtl" ? left + w - PAD : left + PAD;
      wrapped[r][c].forEach((text, i) => ctx.fillText(text, x, top + PAD + LINE * i + LINE / 2, w - PAD * 2));
    });
    top += heights[r];
  });

  // The grid over the fills, the outer border heavier.
  ctx.strokeStyle = "#222222";
  ctx.lineWidth = 2;
  let y = 0;
  for (const h of heights.slice(0, -1)) {
    y += h;
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(width, y);
    ctx.stroke();
  }
  for (const x of lefts) {
    if (x <= 0 || x >= width) continue;
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x, height);
    ctx.stroke();
  }
  ctx.lineWidth = 4;
  ctx.strokeRect(2, 2, width - 4, height - 4);

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/png"));
  if (!blob) throw new Error("table not drawn");
  return { blob, width, height };
}
