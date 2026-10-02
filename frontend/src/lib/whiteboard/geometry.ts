/**
 * The geometry instruments' arithmetic (spec 039 · US9, FR-029), in page units.
 * No DOM: every snap, angle and arc is tested as numbers.
 *
 * An instrument is NEVER saved — only what is drawn along it: a straight line
 * (ruler, set square, protractor ray) or an arc as a polyline (compass).
 */

export type Point = [number, number];

export type InstrumentKind = "ruler" | "protractor" | "compass" | "set-square";

export interface Instrument {
  kind: InstrumentKind;
  /** The instrument's anchor: the ruler's and set square's corner, the protractor's and compass's centre. */
  x: number;
  y: number;
  /** Degrees, clockwise. */
  rotation: number;
  /** The ruler's and set square's length, the protractor's and compass's radius. */
  size: number;
}

/** One centimetre on the instruments, in page units (the page is 48 cm wide). */
export const CM = 40;

export const INSTRUMENT_SIZE: Record<InstrumentKind, number> = {
  ruler: 15 * CM,
  protractor: 220,
  compass: 160,
  "set-square": 10 * CM,
};

export const RULER_WIDTH = 70;

const rad = (deg: number) => (deg * Math.PI) / 180;

export function rotate([x, y]: Point, [cx, cy]: Point, deg: number): Point {
  const c = Math.cos(rad(deg));
  const s = Math.sin(rad(deg));
  return [cx + (x - cx) * c - (y - cy) * s, cy + (x - cx) * s + (y - cy) * c];
}

export function distance([x1, y1]: Point, [x2, y2]: Point): number {
  return Math.hypot(x2 - x1, y2 - y1);
}

/** The point of segment a→b nearest to p. */
export function projectOntoSegment(p: Point, a: Point, b: Point): Point {
  const dx = b[0] - a[0];
  const dy = b[1] - a[1];
  const length2 = dx * dx + dy * dy;
  if (length2 === 0) return a;
  const t = Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / length2));
  return [a[0] + t * dx, a[1] + t * dy];
}

/**
 * The edges a line can be drawn along. The ruler: its top edge. The set square
 * (a right isosceles triangle with its right angle at the anchor): both legs and
 * the hypotenuse.
 */
export function drawingEdges(inst: Instrument): [Point, Point][] {
  const anchor: Point = [inst.x, inst.y];
  const at = (dx: number, dy: number) => rotate([inst.x + dx, inst.y + dy], anchor, inst.rotation);

  if (inst.kind === "ruler") return [[at(0, 0), at(inst.size, 0)]];
  if (inst.kind === "set-square") {
    const corner = at(0, 0);
    const a = at(inst.size, 0);
    const b = at(0, -inst.size);
    return [
      [corner, a],
      [corner, b],
      [a, b],
    ];
  }
  return [];
}

/** The edge nearest to p, and where p falls on it. */
export function snapToEdge(inst: Instrument, p: Point): { edge: [Point, Point]; point: Point } | null {
  let best: { edge: [Point, Point]; point: Point; d: number } | null = null;
  for (const edge of drawingEdges(inst)) {
    const point = projectOntoSegment(p, edge[0], edge[1]);
    const d = distance(point, p);
    if (!best || d < best.d) best = { edge, point, d };
  }
  return best && { edge: best.edge, point: best.point };
}

/**
 * The angle of p round a protractor, in whole degrees, 0 along the protractor's
 * base to the right and rising anticlockwise up to 180 — as it is printed.
 */
export function protractorAngle(inst: Instrument, p: Point): number {
  const local = rotate(p, [inst.x, inst.y], -inst.rotation);
  const deg = (Math.atan2(-(local[1] - inst.y), local[0] - inst.x) * 180) / Math.PI;
  // Below the base (and a point ON the left base, where −0 makes atan2 say −180)
  // goes to the nearer end: 180 on the left, 0 on the right.
  if (deg < 0) return deg < -90 ? 180 : 0;
  return Math.round(deg) || 0; // never −0
}

/** The point on a protractor's rim at a printed angle. */
export function protractorRim(inst: Instrument, angle: number): Point {
  const local: Point = [inst.x + inst.size * Math.cos(rad(angle)), inst.y - inst.size * Math.sin(rad(angle))];
  return rotate(local, [inst.x, inst.y], inst.rotation);
}

/** The angle of p round a centre, degrees clockwise from the right, unbounded. */
export function bearing([cx, cy]: Point, [x, y]: Point): number {
  return (Math.atan2(y - cy, x - cx) * 180) / Math.PI;
}

/**
 * Follow a sweep round the compass: the change from the last bearing, taken the
 * short way round, so a sweep may pass 180° and go on past a full turn.
 */
export function sweep(previous: number, next: number): number {
  let delta = next - previous;
  while (delta > 180) delta -= 360;
  while (delta < -180) delta += 360;
  return delta;
}

/** An arc as points: centre, radius, from `start` through `span` degrees (clamped to a full turn). */
export function arcPoints([cx, cy]: Point, radius: number, start: number, span: number): Point[] {
  const turn = Math.max(-360, Math.min(360, span));
  const steps = Math.max(2, Math.ceil(Math.abs(turn) / 4));
  return Array.from({ length: steps + 1 }, (_, i) => {
    const a = rad(start + (turn * i) / steps);
    return [cx + radius * Math.cos(a), cy + radius * Math.sin(a)] as Point;
  });
}

/**
 * The shape Excalidraw stores a line in: its first point as the element's x/y,
 * every point relative to it — so it reopens exactly where it was drawn (SC-011).
 */
export function lineSkeleton(points: Point[]): { x: number; y: number; points: Point[] } {
  const [x, y] = points[0];
  return { x, y, points: points.map(([px, py]) => [px - x, py - y] as Point) };
}

/** A length in centimetres, one decimal. */
export function centimetres(length: number): number {
  return Math.round((length / CM) * 10) / 10;
}
