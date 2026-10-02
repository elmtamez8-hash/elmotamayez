"use client";

import { useEffect, useRef, useState } from "react";

import { resizeSpotlight, SPOTLIGHT } from "@/lib/whiteboard/presenter";
import { WB } from "@/lib/whiteboard/strings";

/**
 * The spotlight (US8): the whole board darkens except a circle around the
 * pointer, so the class looks where the teacher explains. `[` and `]` shrink and
 * grow the circle; Escape puts it away.
 *
 * `pointer-events: none` and a PASSIVE listener: the pen draws inside the
 * circle as usual. A display layer only — nothing reaches the page (FR-021).
 */
export function Spotlight({ onClose }: { onClose: () => void }) {
  const ref = useRef<HTMLDivElement>(null);
  const [radius, setRadius] = useState(SPOTLIGHT.initial);
  const at = useRef({ x: -1000, y: -1000 });

  // Position is written straight to the style: a re-render per pointer move would
  // be the one thing here that could slow the pen.
  const paint = (r: number) => {
    const node = ref.current;
    if (!node) return;
    const { x, y } = at.current;
    node.style.background = `radial-gradient(circle ${r}px at ${x}px ${y}px, transparent 0, transparent ${r - 2}px, rgba(0,0,0,0.82) ${r}px)`;
  };

  useEffect(() => {
    const onMove = (event: PointerEvent) => {
      const box = ref.current?.getBoundingClientRect();
      if (!box) return;
      at.current = { x: event.clientX - box.left, y: event.clientY - box.top };
      paint(radius);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.target instanceof Element && event.target.closest("textarea, input, select, [contenteditable]")) return;
      if (event.key === "Escape") onClose();
      if (event.key === "]") setRadius((r) => resizeSpotlight(r, true));
      if (event.key === "[") setRadius((r) => resizeSpotlight(r, false));
    };
    paint(radius);
    window.addEventListener("pointermove", onMove, { passive: true });
    window.addEventListener("keydown", onKey);
    return () => {
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("keydown", onKey);
    };
  }, [radius, onClose]);

  return (
    <div
      ref={ref}
      role="presentation"
      aria-label={WB.presenter.spotlightOn}
      className="pointer-events-none absolute inset-0"
      style={{ zIndex: 5 }}
      data-effect="spotlight"
      data-radius={radius}
    />
  );
}
