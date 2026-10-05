import { FALLBACK_FAMILY } from "@/lib/whiteboard/arabic-font";
import type { WbCustomData } from "@/lib/whiteboard/custom-data";

/**
 * A graph on the board (the rich-object pattern, like the table): the
 * functions, the axes' ranges and the marked points are the source
 * (`customData`); the picture is drawn from them, so «تعديل» reopens them.
 * The functions are read by mathjs (owner decision, 2026-10-04): it compiles
 * without `eval` (since v4), which the site's script policy would refuse.
 */
export type GraphData = Extract<WbCustomData, { kind: "graph" }>;
export type GraphPoint = GraphData["points"][number];

/** Dark enough to read on white through a compressed stream, and apart from each other. */
export const GRAPH_COLORS = ["#1d4ed8", "#c2410c", "#15803d", "#7e22ce"] as const;
export const MAX_FUNCTIONS = 4;
export const MAX_POINTS = 8;

const WIDTH = 1200;
const HEIGHT = 900;
const MARGIN = 36;
const SAMPLES = 800;
const SCALE = 2;
const NUMBER_FONT = 22;

export class GraphError extends Error {
  /** Which function could not be read (its index in `functions`). */
  constructor(readonly index: number) {
    super(`function ${index} not understood`);
  }
}

export function blankGraph(): GraphData {
  return { kind: "graph", v: 1, functions: [{ expr: "x^2 - 3", color: GRAPH_COLORS[0] }], x: [-5, 5], y: null, points: [], angle: "rad" };
}

type Compiled = (x: number) => number;
type MathJs = typeof import("mathjs");

let parser: Promise<MathJs["compile"]> | null = null;

/** mathjs, loaded on first use, with the functions that reach outside a formula turned off (its security guide). */
function compiler(): Promise<MathJs["compile"]> {
  parser ??= import("mathjs").then(({ create, all }) => {
    const math = create(all);
    const compile = math.compile;
    const off = (name: string) => () => {
      throw new Error(`${name} is disabled`);
    };
    math.import(
      Object.fromEntries(
        [
          ...["import", "createUnit", "reviver", "evaluate", "parse", "simplify", "derivative", "resolve"],
          // `config` changed mathjs for every later graph (all curves went blank), and the
          // matrix makers let one formula fill the tab's memory (caught in review).
          ...["config", "compile", "parser", "zeros", "ones", "identity", "range", "matrix", "sparse", "diag", "random", "randomInt", "pickRandom"],
        ].map((name) => [name, off(name)]),
      ),
      { override: true },
    );
    return compile;
  });
  return parser;
}

const RAD = Math.PI / 180;
/** In degrees: the trig functions take and give degrees (as the calculator's DEG). */
const DEGREES = {
  sin: (v: number) => Math.sin(v * RAD),
  cos: (v: number) => Math.cos(v * RAD),
  tan: (v: number) => Math.tan(v * RAD),
  cot: (v: number) => 1 / Math.tan(v * RAD),
  sec: (v: number) => 1 / Math.cos(v * RAD),
  csc: (v: number) => 1 / Math.sin(v * RAD),
  asin: (v: number) => Math.asin(v) / RAD,
  acos: (v: number) => Math.acos(v) / RAD,
  atan: (v: number) => Math.atan(v) / RAD,
  acot: (v: number) => Math.atan(1 / v) / RAD,
  asec: (v: number) => Math.acos(1 / v) / RAD,
  acsc: (v: number) => Math.asin(1 / v) / RAD,
  atan2: (y: number, x: number) => Math.atan2(y, x) / RAD,
};

/**
 * The graph as this code draws it, whatever the scene carried: at most four
 * functions as text, numbers where numbers go. A crafted or broken element
 * opened with «تعديل» must not take the board down (caught in review).
 */
export function normaliseGraph(data: Partial<GraphData>): GraphData {
  const num = (n: unknown, fallback: number) => (typeof n === "number" && Number.isFinite(n) ? n : fallback);
  const pair = (p: unknown, fallback: [number, number]): [number, number] =>
    Array.isArray(p) ? [num(p[0], fallback[0]), num(p[1], fallback[1])] : fallback;
  const functions = (Array.isArray(data.functions) ? data.functions : [])
    .slice(0, MAX_FUNCTIONS)
    .map((f, i) => ({ expr: typeof f?.expr === "string" ? f.expr : "", color: GRAPH_COLORS[i] }));
  return {
    kind: "graph",
    v: 1,
    functions: functions.length > 0 ? functions : [{ expr: "", color: GRAPH_COLORS[0] }],
    x: pair(data.x, [-5, 5]),
    y: data.y ? pair(data.y, [-5, 5]) : null,
    points: (Array.isArray(data.points) ? data.points : [])
      .slice(0, MAX_POINTS)
      .filter((p) => Number.isFinite(p?.x) && Number.isFinite(p?.y))
      .map((p) => ({ x: p.x, y: p.y, label: typeof p.label === "string" ? p.label : "" })),
    angle: data.angle === "deg" ? "deg" : "rad",
  };
}

/** «y = …» and «f(x) = …» are what a teacher writes; the formula is what follows. */
export function formula(expr: string): string {
  return expr.replace(/^\s*(?:y|f\s*\(\s*x\s*\))\s*=/i, "").trim();
}

/**
 * Each function as `x → y`, NaN where it has no real value (a gap in the
 * curve). Throws `GraphError` naming the first one that is not a formula in x.
 */
export async function compileFunctions(data: GraphData): Promise<Compiled[]> {
  const compile = await compiler();
  return data.functions.map(({ expr }, index) => {
    const body = formula(expr);
    let code: import("mathjs").EvalFunction;
    try {
      if (!body) throw new Error("empty");
      code = compile(body);
    } catch {
      throw new GraphError(index);
    }
    const scope = new Map<string, unknown>(data.angle === "deg" ? Object.entries(DEGREES) : []);
    const at = (x: number): number => {
      scope.set("x", x);
      const value: unknown = code.evaluate(scope);
      return typeof value === "number" && Number.isFinite(value) ? value : Number.NaN;
    };
    // A formula naming anything but x (or a function mathjs knows) throws at every x.
    try {
      for (const x of [0.5, 1.3, -2.7]) at(x);
    } catch {
      throw new GraphError(index);
    }
    return (x: number) => {
      try {
        return at(x);
      } catch {
        return Number.NaN;
      }
    };
  });
}

/** The y range when the teacher left it open: what the curves and points reach, the spikes (an asymptote) trimmed. */
export function autoRange(values: number[]): [number, number] {
  const finite = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (finite.length === 0) return [-5, 5];
  const pick = (q: number) => finite[Math.min(finite.length - 1, Math.max(0, Math.round(q * (finite.length - 1))))];
  let [low, high] = finite.length > 50 ? [pick(0.02), pick(0.98)] : [finite[0], finite[finite.length - 1]];
  if (high - low < 1e-9) [low, high] = [low - 1, high + 1];
  const pad = (high - low) * 0.1;
  return [low - pad, high + pad];
}

/** A grid step of 1, 2 or 5 × 10ⁿ giving about `lines` lines across `span`. */
export function niceStep(span: number, lines = 10): number {
  const raw = span / lines;
  const power = 10 ** Math.floor(Math.log10(raw));
  const unit = raw / power;
  return (unit < 1.5 ? 1 : unit < 3.5 ? 2 : unit < 7.5 ? 5 : 10) * power;
}

/**
 * The grid lines' values from `low` to `high`, or none when there would be too
 * many to draw or the step no longer moves a number that size (1e20 + 5000 is
 * 1e20) — both froze the tab in a loop (caught in review).
 */
export function ticks(low: number, high: number, step: number): number[] {
  const first = Math.ceil(low / step);
  const count = Math.floor(high / step + 1e-6) - first + 1;
  if (!Number.isFinite(count) || count < 1 || count > 200 || first * step + step === first * step) return [];
  return Array.from({ length: count }, (_, i) => (first + i) * step);
}

/** A number on an axis: no float noise (0.30000000000000004), a minus sign that reads. */
export function tickLabel(value: number): string {
  const clean = Math.abs(value) < 1e-9 ? 0 : Number(value.toPrecision(10));
  return String(clean).replace("-", "−");
}

/** The ranges drawn: the teacher's, or worked out; a backwards or empty one is put right. */
export function ranges(data: GraphData, curves: Compiled[]): { x: [number, number]; y: [number, number] } {
  const order = ([a, b]: [number, number]): [number, number] => (a < b ? [a, b] : a > b ? [b, a] : [a - 1, a + 1]);
  const x = order([Number(data.x[0]) || 0, Number(data.x[1]) || 0]);
  if (data.y) return { x, y: order([Number(data.y[0]) || 0, Number(data.y[1]) || 0]) };
  const values: number[] = data.points.map((p) => p.y);
  for (const f of curves) for (let i = 0; i <= 200; i++) values.push(f(x[0] + ((x[1] - x[0]) * i) / 200));
  return { x, y: autoRange(values) };
}

/** The graph's picture, PNG, in the board's units. Always left to right: it is mathematics. */
export async function renderGraph(input: GraphData): Promise<{ blob: Blob; width: number; height: number }> {
  const data = normaliseGraph(input);
  const curves = await compileFunctions(data);
  const { x: [x0, x1], y: [y0, y1] } = ranges(data, curves);
  const font = (size: number) => `${size}px "${FALLBACK_FAMILY}", sans-serif`;
  await document.fonts?.load(font(NUMBER_FONT), data.points.map((p) => p.label).join(" ") || "ب").catch(() => undefined);

  const canvas = document.createElement("canvas");
  canvas.width = WIDTH * SCALE;
  canvas.height = HEIGHT * SCALE;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("no canvas");
  ctx.scale(SCALE, SCALE);
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, WIDTH, HEIGHT);

  const left = MARGIN;
  const top = MARGIN;
  const w = WIDTH - MARGIN * 2;
  const h = HEIGHT - MARGIN * 2;
  const px = (x: number) => left + ((x - x0) / (x1 - x0)) * w;
  const py = (y: number) => top + h - ((y - y0) / (y1 - y0)) * h;

  // The grid, then the axes (at zero, or along the edge zero is past).
  const stepX = niceStep(x1 - x0);
  const stepY = niceStep(y1 - y0, 8);
  ctx.strokeStyle = "#d4d4d8";
  ctx.lineWidth = 1;
  const columns = ticks(x0, x1, stepX);
  const rows = ticks(y0, y1, stepY);
  for (const v of columns) line(ctx, px(v), top, px(v), top + h);
  for (const v of rows) line(ctx, left, py(v), left + w, py(v));

  const axisX = py(Math.min(Math.max(0, y0), y1));
  const axisY = px(Math.min(Math.max(0, x0), x1));
  ctx.strokeStyle = "#111111";
  ctx.lineWidth = 3;
  line(ctx, left, axisX, left + w, axisX);
  line(ctx, axisY, top, axisY, top + h);

  ctx.fillStyle = "#111111";
  ctx.font = font(NUMBER_FONT);
  ctx.direction = "ltr";
  ctx.textAlign = "center";
  ctx.textBaseline = "top";
  for (const v of columns) {
    if (Math.abs(v) < stepX / 2) continue;
    line(ctx, px(v), axisX - 6, px(v), axisX + 6);
    ctx.fillText(tickLabel(v), px(v), Math.min(axisX + 10, top + h - NUMBER_FONT));
  }
  ctx.textAlign = "right";
  ctx.textBaseline = "middle";
  for (const v of rows) {
    if (Math.abs(v) < stepY / 2) continue;
    line(ctx, axisY - 6, py(v), axisY + 6, py(v));
    ctx.fillText(tickLabel(v), Math.max(axisY - 10, left + 60), py(v));
  }

  // The curves, inside the plot only, broken where a function has no value or leaps an asymptote.
  ctx.save();
  ctx.beginPath();
  ctx.rect(left, top, w, h);
  ctx.clip();
  ctx.lineWidth = 4;
  ctx.lineJoin = "round";
  curves.forEach((f, i) => {
    ctx.strokeStyle = data.functions[i].color;
    ctx.beginPath();
    let previous = Number.NaN;
    for (let s = 0; s <= SAMPLES; s++) {
      const x = x0 + ((x1 - x0) * s) / SAMPLES;
      const y = f(x);
      // A jump of half the view between two neighbouring samples is an asymptote, not a slope.
      const leap = Number.isFinite(previous) && Math.abs(y - previous) > (y1 - y0) / 2;
      if (!Number.isFinite(y) || leap || !Number.isFinite(previous)) {
        if (Number.isFinite(y)) ctx.moveTo(px(x), py(y));
      } else {
        ctx.lineTo(px(x), py(y));
      }
      previous = y;
    }
    ctx.stroke();
  });
  ctx.restore();

  // Each function's formula in its colour, top left; then the points.
  ctx.textAlign = "left";
  ctx.textBaseline = "top";
  ctx.font = font(26);
  data.functions.forEach(({ expr, color }, i) => {
    const text = `y = ${formula(expr)}`;
    const y = top + 10 + i * 38;
    ctx.fillStyle = "rgba(255,255,255,0.85)";
    ctx.fillRect(left + 8, y - 4, ctx.measureText(text).width + 16, 34);
    ctx.fillStyle = color;
    ctx.fillText(text, left + 16, y);
  });
  ctx.font = font(24);
  for (const point of data.points) {
    if (!Number.isFinite(point.x) || !Number.isFinite(point.y)) continue;
    const [cx, cy] = [px(point.x), py(point.y)];
    if (cx < left || cx > left + w || cy < top || cy > top + h) continue;
    ctx.fillStyle = "#b91c1c";
    ctx.beginPath();
    ctx.arc(cx, cy, 8, 0, Math.PI * 2);
    ctx.fill();
    const label = point.label.trim() || `(${tickLabel(point.x)}، ${tickLabel(point.y)})`;
    ctx.fillStyle = "#111111";
    ctx.direction = "inherit";
    ctx.textBaseline = "bottom";
    ctx.fillText(label, cx + 12, cy - 8);
  }

  ctx.lineWidth = 3;
  ctx.strokeStyle = "#111111";
  ctx.strokeRect(1.5, 1.5, WIDTH - 3, HEIGHT - 3);

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/png"));
  if (!blob) throw new Error("graph not drawn");
  return { blob, width: WIDTH, height: HEIGHT };
}

function line(ctx: CanvasRenderingContext2D, ax: number, ay: number, bx: number, by: number): void {
  ctx.beginPath();
  ctx.moveTo(ax, ay);
  ctx.lineTo(bx, by);
  ctx.stroke();
}
