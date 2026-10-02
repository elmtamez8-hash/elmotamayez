"use client";

import { useEffect, useRef } from "react";

/**
 * A round lens that follows the pointer and shows what is under it twice as big
 * (US9) — for a small detail the stream would blur. It copies pixels from the
 * board's own drawing canvas; nothing is added to the page (FR-032). Esc closes.
 */

export const LENS = { size: 260, zoom: 2 };

export function Magnifier({ onClose }: { onClose: () => void }) {
  const ref = useRef<HTMLCanvasElement>(null);

  // Esc first, on its own: a browser that refuses a drawing context must still
  // be able to put the lens away.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  useEffect(() => {
    const lens = ref.current;
    const ctx = lens?.getContext("2d");
    if (!lens || !ctx) return;
    const ratio = window.devicePixelRatio || 1;
    lens.width = LENS.size * ratio;
    lens.height = LENS.size * ratio;

    let frame = 0;
    let at = { x: 0, y: 0 };

    const draw = () => {
      frame = 0;
      // The board's drawing (not the selection layer) — Excalidraw's static canvas.
      const source = document.querySelector<HTMLCanvasElement>("canvas.excalidraw__canvas:not(.interactive)");
      const host = lens.parentElement?.getBoundingClientRect();
      if (!source || !host) return;
      const box = source.getBoundingClientRect();
      const scaleX = source.width / box.width;
      const scaleY = source.height / box.height;
      const span = LENS.size / LENS.zoom;

      ctx.clearRect(0, 0, lens.width, lens.height);
      ctx.save();
      ctx.beginPath();
      ctx.arc(lens.width / 2, lens.height / 2, lens.width / 2, 0, Math.PI * 2);
      ctx.clip();
      ctx.fillStyle = getComputedStyle(source).backgroundColor || "#fff";
      ctx.fillRect(0, 0, lens.width, lens.height);
      ctx.drawImage(
        source,
        (at.x - box.left - span / 2) * scaleX,
        (at.y - box.top - span / 2) * scaleY,
        span * scaleX,
        span * scaleY,
        0,
        0,
        lens.width,
        lens.height,
      );
      ctx.restore();

      // Above and to the side of the pointer, so the pen tip stays in view.
      lens.style.left = `${at.x - host.left - LENS.size / 2}px`;
      lens.style.top = `${at.y - host.top - LENS.size - 30}px`;
    };

    const onMove = (event: PointerEvent) => {
      at = { x: event.clientX, y: event.clientY };
      if (!frame) frame = requestAnimationFrame(draw);
    };
    window.addEventListener("pointermove", onMove, { passive: true });
    return () => {
      window.removeEventListener("pointermove", onMove);
      cancelAnimationFrame(frame);
    };
  }, []);

  return (
    <canvas
      ref={ref}
      aria-hidden
      data-effect="magnifier"
      className="pointer-events-none absolute rounded-full"
      style={{ zIndex: 6, width: LENS.size, height: LENS.size, left: -9999, top: -9999, boxShadow: "0 0 0 4px #111827, 0 10px 30px rgba(0,0,0,0.4)" }}
    />
  );
}
