/**
 * Encouragement and pointer effects (spec 039 · US10, US12) — the motion, with no
 * canvas in it, so it is tested as plain numbers.
 *
 * ⚠️ A DISPLAY LAYER ONLY (FR-032, SC-010). Nothing here knows the board: no
 * element is added, nothing reaches the saved page or an export. The components
 * that draw this are handed no Excalidraw API at all.
 *
 * Sized for the stream, not the teacher's screen: particles are 18–44 px so a
 * compressed video on a low-end phone still shows them, and the colours are the
 * saturated ones that survive on white, black and green boards alike.
 */

export type Celebration = "applause" | "balloons" | "party" | "stars";
export type TrailStyle = "off" | "neon" | "sparks" | "rainbow";

export const CELEBRATIONS: Celebration[] = ["applause", "balloons", "party", "stars"];
export const TRAIL_STYLES: TrailStyle[] = ["off", "neon", "sparks", "rainbow"];

/** How long a celebration lasts on screen. */
export const CELEBRATION_MS = 3200;

const COLOURS = ["#ff3b30", "#ffcc00", "#34c759", "#0a84ff", "#ff2d92", "#ff9500", "#af52de"];

export interface Particle {
  x: number;
  y: number;
  vx: number;
  vy: number;
  /** Pixels per second², positive is down. Balloons rise, so theirs is negative. */
  gravity: number;
  size: number;
  colour: string;
  rotation: number;
  spin: number;
  /** Seconds left; the particle fades over its last second. */
  life: number;
  shape: "confetti" | "balloon" | "star" | "glyph";
  glyph?: string;
}

type Random = () => number;

const between = (rand: Random, min: number, max: number) => min + rand() * (max - min);
const pick = <T,>(rand: Random, list: T[]) => list[Math.floor(rand() * list.length) % list.length];

/** The particles one celebration starts with, on a `width × height` stage. */
export function spawn(kind: Celebration, width: number, height: number, rand: Random = Math.random): Particle[] {
  const life = CELEBRATION_MS / 1000;

  switch (kind) {
    case "applause":
      // Clapping hands rising from the bottom edge and spreading out.
      return Array.from({ length: 18 }, () => ({
        x: between(rand, width * 0.15, width * 0.85),
        y: height + 40,
        vx: between(rand, -60, 60),
        vy: between(rand, -height * 0.55, -height * 0.35),
        gravity: height * 0.12,
        size: between(rand, 34, 44),
        colour: "#000",
        rotation: between(rand, -0.3, 0.3),
        spin: between(rand, -1, 1),
        life: between(rand, life * 0.7, life),
        shape: "glyph" as const,
        glyph: "👏",
      }));

    case "balloons":
      return Array.from({ length: 22 }, () => ({
        x: between(rand, 0, width),
        y: height + between(rand, 20, height * 0.4),
        vx: between(rand, -20, 20),
        vy: between(rand, -height * 0.45, -height * 0.28),
        gravity: -height * 0.02,
        size: between(rand, 28, 42),
        colour: pick(rand, COLOURS),
        rotation: 0,
        spin: between(rand, -0.4, 0.4),
        life,
        shape: "balloon" as const,
      }));

    case "party":
      // Confetti thrown up from both bottom corners.
      return Array.from({ length: 140 }, (_, i) => {
        const left = i % 2 === 0;
        return {
          x: left ? 0 : width,
          y: height,
          vx: (left ? 1 : -1) * between(rand, width * 0.15, width * 0.45),
          vy: between(rand, -height * 1.3, -height * 0.7),
          gravity: height * 0.9,
          size: between(rand, 10, 18),
          colour: pick(rand, COLOURS),
          rotation: between(rand, 0, Math.PI * 2),
          spin: between(rand, -8, 8),
          life: between(rand, life * 0.8, life),
          shape: "confetti" as const,
        };
      });

    case "stars":
      // A burst of stars from the centre of the page.
      return Array.from({ length: 36 }, (_, i) => {
        const angle = (i / 36) * Math.PI * 2 + between(rand, -0.1, 0.1);
        const speed = between(rand, height * 0.25, height * 0.6);
        return {
          x: width / 2,
          y: height / 2,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed,
          gravity: height * 0.05,
          size: between(rand, 18, 34),
          colour: pick(rand, ["#ffcc00", "#ffd60a", "#ff9500", "#ffe066"]),
          rotation: between(rand, 0, Math.PI),
          spin: between(rand, -3, 3),
          life: between(rand, life * 0.6, life),
          shape: "star" as const,
        };
      });
  }
}

/** Advance every particle by `dt` seconds; the dead ones are dropped. */
export function step(particles: Particle[], dt: number): Particle[] {
  const next: Particle[] = [];
  for (const p of particles) {
    const life = p.life - dt;
    if (life <= 0) continue;
    next.push({
      ...p,
      life,
      vy: p.vy + p.gravity * dt,
      x: p.x + p.vx * dt,
      y: p.y + p.vy * dt,
      rotation: p.rotation + p.spin * dt,
    });
  }
  return next;
}

/** 1 until the last second of a particle's life, then down to 0. */
export function opacity(p: Particle): number {
  return Math.max(0, Math.min(1, p.life));
}

// ---------------------------------------------------------------------------
// The pointer trail

export interface TrailPoint {
  x: number;
  y: number;
  /** Seconds since it was laid down. */
  age: number;
}

/** A trail point fades out over this long. */
export const TRAIL_SECONDS = 0.6;

/** Age every point and drop the ones older than the trail. */
export function ageTrail(points: TrailPoint[], dt: number): TrailPoint[] {
  const next: TrailPoint[] = [];
  for (const p of points) {
    const age = p.age + dt;
    if (age < TRAIL_SECONDS) next.push({ ...p, age });
  }
  return next;
}

/** The colour of the `index`-th point of a trail. */
export function trailColour(style: TrailStyle, index: number): string {
  if (style === "rainbow") return `hsl(${(index * 14) % 360} 95% 55%)`;
  if (style === "sparks") return index % 3 === 0 ? "#ffffff" : "#ffcc00";
  return "#00e5ff";
}
