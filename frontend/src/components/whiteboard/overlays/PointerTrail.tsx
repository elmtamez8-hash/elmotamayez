"use client";

import { useEffect, useRef } from "react";

import { ageTrail, TRAIL_SECONDS, trailColour, type TrailPoint, type TrailStyle } from "@/lib/whiteboard/effects";

/**
 * A glowing trail behind the pointer, so the class can follow where the teacher
 * points (US12). Its own canvas, `pointer-events: none`, fed by a PASSIVE
 * pointermove listener — Excalidraw gets every event as before, so the pen is
 * not slowed (FR-032). The animation loop runs only while there is a trail to
 * draw, and stops when the pointer rests.
 */
export function PointerTrail({ style }: { style: Exclude<TrailStyle, "off"> }) {
  const ref = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = ref.current;
    const ctx = canvas?.getContext("2d");
    if (!canvas || !ctx) return;

    const ratio = window.devicePixelRatio || 1;
    const resize = () => {
      const box = canvas.getBoundingClientRect();
      canvas.width = box.width * ratio;
      canvas.height = box.height * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    };
    resize();

    let points: TrailPoint[] = [];
    let frame = 0;
    let last = 0;

    const draw = (now: number) => {
      points = ageTrail(points, last ? (now - last) / 1000 : 0);
      last = now;
      const box = canvas.getBoundingClientRect();
      ctx.clearRect(0, 0, box.width, box.height);

      ctx.lineCap = "round";
      for (let i = 1; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];
        const fade = 1 - b.age / TRAIL_SECONDS;
        ctx.globalAlpha = fade;
        ctx.strokeStyle = trailColour(style, i);
        ctx.lineWidth = (style === "sparks" ? 4 : 8) * fade + 2;
        ctx.shadowColor = ctx.strokeStyle;
        ctx.shadowBlur = style === "neon" ? 18 : 8;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
        if (style === "sparks" && i % 4 === 0) {
          ctx.fillStyle = trailColour(style, i);
          ctx.fillRect(b.x + (Math.random() - 0.5) * 18, b.y + (Math.random() - 0.5) * 18, 3, 3);
        }
      }

      frame = points.length > 0 ? requestAnimationFrame(draw) : 0;
      if (frame === 0) last = 0;
    };

    const onMove = (event: PointerEvent) => {
      const box = canvas.getBoundingClientRect();
      points.push({ x: event.clientX - box.left, y: event.clientY - box.top, age: 0 });
      if (frame === 0) frame = requestAnimationFrame(draw);
    };

    window.addEventListener("pointermove", onMove, { passive: true });
    window.addEventListener("resize", resize);
    return () => {
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("resize", resize);
      cancelAnimationFrame(frame);
    };
  }, [style]);

  return <canvas ref={ref} aria-hidden className="pointer-events-none absolute inset-0 h-full w-full" style={{ zIndex: 6 }} data-trail={style} />;
}
