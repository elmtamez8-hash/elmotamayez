"use client";

import { useRef, useState } from "react";

import { WB } from "@/lib/whiteboard/strings";

/**
 * A curtain over the board (US9): it hides the answer, and the teacher drags its
 * edge — or presses «اكشف قليلاً» — to reveal it bit by bit. It covers from the
 * bottom up, so a question written at the top stays visible. Display only (FR-032).
 */

/** How much of the board's height the curtain covers, 0–1. */
export const CURTAIN_START = 0.6;
export const CURTAIN_STEP = 0.1;

export function Curtain({ onClose }: { onClose: () => void }) {
  const [cover, setCover] = useState(CURTAIN_START);
  const ref = useRef<HTMLDivElement>(null);

  const dragTo = (clientY: number) => {
    const box = ref.current?.parentElement?.getBoundingClientRect();
    if (!box) return;
    setCover(Math.min(1, Math.max(0, (box.bottom - clientY) / box.height)));
  };

  return (
    <div
      ref={ref}
      data-effect="curtain"
      data-cover={cover.toFixed(2)}
      className="absolute inset-x-0 bottom-0"
      style={{ zIndex: 5, height: `${cover * 100}%`, background: "repeating-linear-gradient(90deg,#7a1f2b 0 34px,#8f2633 34px 68px)" }}
    >
      {/* The pull: drag it, or use the buttons — a keyboard or a pen tablet alike. */}
      <div
        role="slider"
        tabIndex={0}
        aria-label={WB.tools.curtain}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(cover * 100)}
        onPointerDown={(event) => event.currentTarget.setPointerCapture(event.pointerId)}
        onPointerMove={(event) => event.buttons === 1 && dragTo(event.clientY)}
        onKeyDown={(event) => {
          if (event.key === "ArrowDown") setCover((c) => Math.max(0, c - CURTAIN_STEP));
          if (event.key === "ArrowUp") setCover((c) => Math.min(1, c + CURTAIN_STEP));
        }}
        className="absolute inset-x-0 top-0 flex h-10 -translate-y-1/2 cursor-ns-resize items-center justify-center"
      >
        <span className="h-3 w-40 rounded-full bg-white/80 shadow" />
      </div>
      <div className="absolute bottom-3 end-3 flex gap-2">
        <button
          type="button"
          onClick={() => setCover((c) => Math.max(0, c - CURTAIN_STEP))}
          className="rounded-lg bg-white/90 px-3 py-1 text-sm font-semibold text-[#7a1f2b]"
        >
          {WB.tools.reveal}
        </button>
        <button type="button" onClick={onClose} className="rounded-lg bg-white/90 px-3 py-1 text-sm font-semibold text-[#7a1f2b]">
          {WB.tools.closeCurtain}
        </button>
      </div>
    </div>
  );
}
