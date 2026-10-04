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

/**
 * Every row as long as the longest, every column with a width, and every merged
 * cell inside the grid and over no other — a row or column removed cuts the
 * merge short, and a scene could carry anything.
 */
export function normalise(data: TableData): TableData {
  const cols = Math.max(1, data.colWidths.length, ...data.rows.map((row) => row.cells.length));
  const taken = data.rows.map(() => Array<boolean>(cols).fill(false));
  const rows = data.rows.map((row, r) => ({
    cells: Array.from({ length: cols }, (_, c): TableCell => {
      const cell = row.cells[c] ?? { text: "" };
      // Only our own fills: a scene could carry anything, and it reaches `style`.
      const fill = cell.fill && (TABLE_FILLS as readonly string[]).includes(cell.fill) ? cell.fill : undefined;
      const span = taken[r][c] ? null : spanAt(cell.span, r, c, taken);
      if (span) for (let y = r; y < r + span[0]; y++) for (let x = c; x < c + span[1]; x++) taken[y][x] = true;
      return { text: cell.text, ...(fill ? { fill } : {}), ...(span ? { span } : {}) };
    }),
  }));
  return { ...data, rows, colWidths: Array.from({ length: cols }, (_, i) => data.colWidths[i] ?? COLUMN) };
}

/** A merge's size cut to the free cells right of and below [r, c]; null when one cell. */
function spanAt(span: unknown, r: number, c: number, taken: boolean[][]): [number, number] | null {
  if (!Array.isArray(span)) return null;
  const size = (n: unknown) => (Number.isInteger(n) && (n as number) > 0 ? (n as number) : 1);
  let w = Math.min(size(span[1]), taken[r].length - c);
  for (let x = c + 1; x < c + w; x++) if (taken[r][x]) w = x - c;
  let h = Math.min(size(span[0]), taken.length - r);
  for (let y = r + 1; y < r + h; y++) if (taken[y].slice(c, c + w).some(Boolean)) h = y - r;
  return h * w > 1 ? [h, w] : null;
}

/** For each cell, the merged cell that covers it ([row, col]), or null when it is drawn itself. */
export function coveredBy(data: TableData): ([number, number] | null)[][] {
  const out = data.rows.map((row) => row.cells.map((): [number, number] | null => null));
  data.rows.forEach((row, r) =>
    row.cells.forEach((cell, c) => {
      const [h, w] = cell.span ?? [1, 1];
      for (let y = r; y < r + h; y++) for (let x = c; x < c + w; x++) if (y !== r || x !== c) out[y][x] = [r, c];
    }),
  );
  return out;
}

export type CellRange = { top: number; left: number; bottom: number; right: number };

/**
 * The cells between two corners, grown until no merged cell sticks out of it —
 * what a spreadsheet selects. `left`/`right` are column indexes, not screen sides.
 */
export function cellRange(data: TableData, [r1, c1]: [number, number], [r2, c2]: [number, number]): CellRange {
  const range = { top: Math.min(r1, r2), left: Math.min(c1, c2), bottom: Math.max(r1, r2), right: Math.max(c1, c2) };
  for (let grew = true; grew; ) {
    grew = false;
    data.rows.forEach((row, r) =>
      row.cells.forEach((cell, c) => {
        const [h, w] = cell.span ?? [1, 1];
        const meets = r <= range.bottom && r + h - 1 >= range.top && c <= range.right && c + w - 1 >= range.left;
        if (!meets) return;
        const next = { top: Math.min(range.top, r), left: Math.min(range.left, c), bottom: Math.max(range.bottom, r + h - 1), right: Math.max(range.right, c + w - 1) };
        if (next.top !== range.top || next.left !== range.left || next.bottom !== range.bottom || next.right !== range.right) {
          Object.assign(range, next);
          grew = true;
        }
      }),
    );
  }
  return range;
}

/**
 * One cell over the range. Nothing typed is lost (owner decision): every cell's
 * text joins the first one, a line each, in reading order.
 */
export function mergeCells(data: TableData, range: CellRange): TableData {
  const { top, left, bottom, right } = range;
  const inside = (r: number, c: number) => r >= top && r <= bottom && c >= left && c <= right;
  const text = data.rows
    .flatMap((row, r) => row.cells.filter((_, c) => inside(r, c)).map((cell) => cell.text.trim()))
    .filter(Boolean)
    .join("\n");
  return normalise({
    ...data,
    rows: data.rows.map((row, r) => ({
      cells: row.cells.map((cell, c): TableCell => {
        if (r === top && c === left) return { text, ...(cell.fill ? { fill: cell.fill } : {}), span: [bottom - top + 1, right - left + 1] };
        return inside(r, c) ? { text: "" } : cell;
      }),
    })),
  });
}

/** The merged cell back to single cells; its text stays in the first. */
export function splitCell(data: TableData, r: number, c: number): TableData {
  return {
    ...data,
    rows: data.rows.map((row, ri) => (ri !== r ? row : { cells: row.cells.map((cell, ci) => (ci === c ? { text: cell.text, ...(cell.fill ? { fill: cell.fill } : {}) } : cell)) })),
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
  const covered = coveredBy(data);
  const spanWidth = (c: number, cols: number) => data.colWidths.slice(c, c + cols).reduce((sum, x) => sum + x, 0);
  const wrapped = data.rows.map((row, r) =>
    row.cells.map((cell, c) => (covered[r][c] ? [] : lines(measure, cell.text, spanWidth(c, cell.span?.[1] ?? 1) - PAD * 2))),
  );
  const need = (r: number, c: number) => Math.max(1, wrapped[r][c].length) * LINE + PAD * 2;
  // One-row cells set the rows; a taller merged cell then grows its last row.
  const heights = data.rows.map((row, r) => Math.max(LINE + PAD * 2, ...row.cells.map((cell, c) => (covered[r][c] || (cell.span?.[0] ?? 1) > 1 ? 0 : need(r, c)))));
  data.rows.forEach((row, r) =>
    row.cells.forEach((cell, c) => {
      const h = cell.span?.[0] ?? 1;
      if (covered[r][c] || h === 1) return;
      const short = need(r, c) - heights.slice(r, r + h).reduce((sum, x) => sum + x, 0);
      if (short > 0) heights[r + h - 1] += short;
    }),
  );
  const tops = heights.map((_, r) => heights.slice(0, r).reduce((sum, x) => sum + x, 0));
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

  // Each drawn cell — a merged one over its whole box — then its border, the outer one heavier.
  ctx.strokeStyle = "#222222";
  ctx.lineWidth = 2;
  data.rows.forEach((row, r) => {
    row.cells.forEach((cell, c) => {
      if (covered[r][c]) return;
      const [h, cols] = cell.span ?? [1, 1];
      const w = spanWidth(c, cols);
      // The box's left edge: its last column's, right to left.
      const left = Math.min(...lefts.slice(c, c + cols));
      const top = tops[r];
      const tall = heights.slice(r, r + h).reduce((sum, x) => sum + x, 0);
      if (cell.fill) {
        ctx.fillStyle = cell.fill;
        ctx.fillRect(left, top, w, tall);
      }
      ctx.fillStyle = "#111111";
      const x = data.dir === "rtl" ? left + w - PAD : left + PAD;
      wrapped[r][c].forEach((text, i) => ctx.fillText(text, x, top + PAD + LINE * i + LINE / 2, w - PAD * 2));
      ctx.strokeRect(left, top, w, tall);
    });
  });
  ctx.lineWidth = 4;
  ctx.strokeRect(2, 2, width - 4, height - 4);

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/png"));
  if (!blob) throw new Error("table not drawn");
  return { blob, width, height };
}
