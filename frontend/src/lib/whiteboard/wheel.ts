/**
 * The random-pick wheel's arithmetic (spec 039 · US9). Segment `i` spans
 * `[i·s, (i+1)·s)` degrees clockwise from the top, where the pointer is.
 */

/** Names from the teacher's text: one per line, blanks dropped. A bare number N means 1…N. */
export function wheelEntries(text: string): string[] {
  const lines = text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "");
  const only = lines.length === 1 ? /^\d+$/.exec(lines[0].replace(/[٠-٩]/g, (d) => String("٠١٢٣٤٥٦٧٨٩".indexOf(d)))) : null;
  if (only) {
    const n = Math.min(60, Number(only[0]));
    return Array.from({ length: n }, (_, i) => String(i + 1));
  }
  return lines.slice(0, 60);
}

export function pickIndex(count: number, rand: () => number = Math.random): number {
  return Math.min(count - 1, Math.floor(rand() * count));
}

/**
 * The total clockwise turn, from `from` degrees, that stops the CENTRE of
 * segment `index` under the top pointer after at least `turns` full turns.
 */
export function spinTo(from: number, index: number, count: number, turns = 5): number {
  const segment = 360 / count;
  const target = (360 - (index + 0.5) * segment) % 360;
  const current = ((from % 360) + 360) % 360;
  const extra = (target - current + 360) % 360;
  return from + turns * 360 + extra;
}

/** Which segment sits under the pointer at a rotation of `angle` degrees. */
export function segmentAt(angle: number, count: number): number {
  const segment = 360 / count;
  const top = (360 - (((angle % 360) + 360) % 360)) % 360;
  return Math.floor(top / segment) % count;
}
