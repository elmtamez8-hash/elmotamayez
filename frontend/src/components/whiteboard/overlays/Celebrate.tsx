"use client";

import { useEffect, useRef } from "react";

import { CELEBRATION_MS, opacity, spawn, step, type Celebration, type Particle } from "@/lib/whiteboard/effects";

/**
 * One celebration drawn over the board for a few seconds, then gone (US10).
 *
 * ⚠️ NO EXCALIDRAW API IS HANDED IN (FR-032, SC-010): a canvas of its own, above
 * the board, `pointer-events: none` so the pen keeps working through it. When it
 * ends it unmounts itself through `onDone`; nothing is left in the page or an export.
 */
export function Celebrate({ kind, onDone }: { kind: Celebration; onDone: () => void }) {
  const ref = useRef<HTMLCanvasElement>(null);
  const doneRef = useRef(onDone);
  doneRef.current = onDone;

  useEffect(() => {
    const finish = window.setTimeout(() => doneRef.current(), CELEBRATION_MS);

    const canvas = ref.current;
    // jsdom (and a browser refusing a context) has none: the timer still ends it.
    const ctx = canvas?.getContext("2d");
    if (!canvas || !ctx) return () => window.clearTimeout(finish);

    const ratio = window.devicePixelRatio || 1;
    const { width, height } = canvas.getBoundingClientRect();
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    ctx.scale(ratio, ratio);

    let particles: Particle[] = spawn(kind, width, height);
    let last = performance.now();
    let frame = requestAnimationFrame(function draw(now) {
      particles = step(particles, Math.min(0.05, (now - last) / 1000));
      last = now;
      ctx.clearRect(0, 0, width, height);
      for (const p of particles) paint(ctx, p);
      if (particles.length > 0) frame = requestAnimationFrame(draw);
    });

    return () => {
      cancelAnimationFrame(frame);
      window.clearTimeout(finish);
    };
  }, [kind]);

  return <canvas ref={ref} aria-hidden className="pointer-events-none absolute inset-0 h-full w-full" style={{ zIndex: 6 }} data-celebration={kind} />;
}

function paint(ctx: CanvasRenderingContext2D, p: Particle) {
  ctx.save();
  ctx.globalAlpha = opacity(p);
  ctx.translate(p.x, p.y);
  ctx.rotate(p.rotation);
  ctx.fillStyle = p.colour;

  switch (p.shape) {
    case "confetti":
      ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
      break;
    case "star":
      ctx.beginPath();
      for (let i = 0; i < 10; i++) {
        const r = i % 2 === 0 ? p.size / 2 : p.size / 4.5;
        const a = (i / 10) * Math.PI * 2 - Math.PI / 2;
        ctx.lineTo(Math.cos(a) * r, Math.sin(a) * r);
      }
      ctx.closePath();
      ctx.fill();
      break;
    case "glyph":
      ctx.font = `${p.size}px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif`;
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(p.glyph ?? "", 0, 0);
      break;
  }
  ctx.restore();
}
