"use client";

import { useEffect, useRef } from "react";

import { PAGE_HEIGHT, PAGE_WIDTH } from "@/lib/whiteboard/page-model";
import { paintTemplate, type TemplateName } from "@/lib/whiteboard/templates";

/**
 * A round lens that follows the pointer and shows what is under it twice as big
 * (US9) — for a small detail the stream would blur. It copies pixels from the
 * board's own drawing canvas; nothing is added to the page (FR-032). Esc closes.
 */

export const LENS = { size: 260, zoom: 2 };

export function Magnifier({ background, template = null, onClose }: { background: string; template?: TemplateName | null; onClose: () => void }) {
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
      // The canvas is transparent (the board's colour is a layer behind it), so the lens paints the colour itself.
      ctx.fillStyle = background;
      ctx.fillRect(0, 0, lens.width, lens.height);
      // …and the template, which lives in that layer too: where the page sits on
      // screen is in the CSS variables PageCover keeps on Excalidraw's root.
      const root = document.querySelector<HTMLElement>(".wb-board .excalidraw");
      if (template && root) {
        const rootBox = root.getBoundingClientRect();
        const px = (name: string) => parseFloat(root.style.getPropertyValue(name)) || 0;
        const k = px("--wb-w") / PAGE_WIDTH; // screen px per page unit
        const s = lens.width / span; // lens px per screen px
        if (k > 0) {
          const pageX = rootBox.left + px("--wb-x");
          const pageY = rootBox.top + px("--wb-y");
          // The screens (1080 tall, page units) the lens covers.
          const top = Math.floor((at.y - span / 2 - pageY) / k / PAGE_HEIGHT);
          const bottom = Math.floor((at.y + span / 2 - pageY) / k / PAGE_HEIGHT);
          for (let screen = top; screen <= bottom; screen++) {
            ctx.save();
            ctx.translate((pageX - (at.x - span / 2)) * s, (pageY - (at.y - span / 2)) * s);
            ctx.scale(k * s, k * s);
            ctx.translate(0, screen * PAGE_HEIGHT);
            paintTemplate(ctx, template);
            ctx.restore();
          }
        }
      }
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
  }, [background, template]);

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
