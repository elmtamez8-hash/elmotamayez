/**
 * «القلم السحري» (2026-10-03): a hand-drawn stroke recognised as the shape the
 * teacher meant — a line, an arrow, an ellipse, a rectangle, a diamond or a
 * triangle — and swapped for Excalidraw's own clean element. A stroke it is not
 * sure of stays as drawn.
 *
 * ponytail: geometry, not a trained recogniser. The corners come from
 * Ramer–Douglas–Peucker, the ellipse from how far the points stray from the box's
 * ellipse. Shapes beyond these six (stars, clouds, hearts) are the upgrade path —
 * a $1-style template matcher, if teachers ask for them.
 */

export type Point = [number, number];

export type MagicShape =
  | { type: "line"; from: Point; to: Point }
  | { type: "arrow"; from: Point; to: Point }
  | { type: "ellipse" | "rectangle" | "diamond"; x: number; y: number; width: number; height: number }
  | { type: "triangle"; points: [Point, Point, Point] };

const dist = (a: Point, b: Point) => Math.hypot(a[0] - b[0], a[1] - b[1]);

function pathLength(points: Point[]): number {
  let total = 0;
  for (let i = 1; i < points.length; i++) total += dist(points[i - 1], points[i]);
  return total;
}

/** How far `p` lies from the segment a–b. */
function offSegment(p: Point, a: Point, b: Point): number {
  const length = dist(a, b);
  if (length === 0) return dist(p, a);
  const t = Math.max(0, Math.min(1, ((p[0] - a[0]) * (b[0] - a[0]) + (p[1] - a[1]) * (b[1] - a[1])) / length ** 2));
  return dist(p, [a[0] + t * (b[0] - a[0]), a[1] + t * (b[1] - a[1])]);
}

/** Ramer–Douglas–Peucker: the stroke's corners, within `epsilon`. */
export function simplify(points: Point[], epsilon: number): Point[] {
  if (points.length < 3) return points;
  let worst = 0;
  let at = 0;
  for (let i = 1; i < points.length - 1; i++) {
    const d = offSegment(points[i], points[0], points[points.length - 1]);
    if (d > worst) {
      worst = d;
      at = i;
    }
  }
  if (worst <= epsilon) return [points[0], points[points.length - 1]];
  return [...simplify(points.slice(0, at + 1), epsilon).slice(0, -1), ...simplify(points.slice(at), epsilon)];
}

/** The shape a stroke (in board units) was meant to be, or null when unsure. */
export function recognise(points: Point[]): MagicShape | null {
  if (points.length < 4) return null;
  const xs = points.map((p) => p[0]);
  const ys = points.map((p) => p[1]);
  const x = Math.min(...xs);
  const y = Math.min(...ys);
  const width = Math.max(...xs) - x;
  const height = Math.max(...ys) - y;
  const diagonal = Math.hypot(width, height);
  if (diagonal < 24) return null; // a dot or a tick is writing, not a shape

  const start = points[0];
  const end = points[points.length - 1];
  const length = pathLength(points);
  const closed = dist(start, end) < Math.max(0.2 * diagonal, 0.08 * length);

  if (!closed) {
    // Straight when no point strays far from the chord (a length ratio counts the hand's wobble as length).
    const chord = dist(start, end);
    if (chord > 0.85 * diagonal && points.every((p) => offSegment(p, start, end) < 0.07 * chord)) return { type: "line", from: start, to: end };
    // An arrow drawn in one go: a long shaft, then a short head folding back.
    const corners = simplify(points, 0.08 * diagonal);
    if (corners.length >= 3 && corners.length <= 5) {
      const shaft = dist(corners[0], corners[1]);
      const rest = pathLength(corners.slice(1));
      if (shaft > 0.6 * length && rest < 0.75 * shaft) return { type: "arrow", from: corners[0], to: corners[1] };
    }
    return null;
  }

  // Closed: an ellipse when the points keep to the box's ellipse…
  const cx = x + width / 2;
  const cy = y + height / 2;
  const stray =
    points.reduce((sum, [px, py]) => sum + Math.abs(Math.hypot((px - cx) / (width / 2 || 1), (py - cy) / (height / 2 || 1)) - 1), 0) / points.length;

  const ring = [...points.slice(0, -1), start];
  const corners = simplify(ring, 0.1 * diagonal).slice(0, -1);
  // The stroke's start is a vertex only by accident of where the pen came down:
  // drop it when it sits on a straight run between its neighbours.
  if (corners.length > 3 && offSegment(corners[0], corners[corners.length - 1], corners[1]) < 0.1 * diagonal) corners.shift();

  if (stray < 0.12 && corners.length >= 4) return { type: "ellipse", x, y, width, height };
  if (corners.length === 3) return { type: "triangle", points: [corners[0], corners[1], corners[2]] };
  if (corners.length === 4) {
    // …a diamond when its corners sit on the box's edge middles, else a rectangle.
    const middles: Point[] = [
      [cx, y],
      [x + width, cy],
      [cx, y + height],
      [x, cy],
    ];
    const onMiddles = corners.every((c) => middles.some((m) => dist(c, m) < 0.2 * diagonal));
    return { type: onMiddles ? "diamond" : "rectangle", x, y, width, height };
  }
  if (stray < 0.2) return { type: "ellipse", x, y, width, height };
  return null;
}
