"use client";

import { useEffect } from "react";

import { WB } from "@/lib/whiteboard/strings";

/** How long «انتباه!» stays on the board. */
export const ATTENTION_MS = 2400;

/**
 * «انتباه!» across the board with the gavel's knocks (US10) — calls a wandering
 * class back. Large and high-contrast, for a compressed stream on a small phone.
 */
export function AttentionBanner({ onDone }: { onDone: () => void }) {
  useEffect(() => {
    const end = window.setTimeout(onDone, ATTENTION_MS);
    return () => window.clearTimeout(end);
  }, [onDone]);

  return (
    <div className="pointer-events-none absolute inset-0 flex items-center justify-center" style={{ zIndex: 6 }} data-effect="attention">
      <style>{`
        @keyframes wb-knock { 0%, 26%, 52% { transform: scale(1.25) rotate(-3deg); } 13%, 39%, 65%, 100% { transform: scale(1) rotate(0); } }
      `}</style>
      <p
        role="status"
        className="rounded-3xl px-12 py-6 font-extrabold"
        style={{
          fontSize: "clamp(56px, 11vw, 160px)",
          background: "#d62828",
          color: "#ffffff",
          boxShadow: "0 0 0 10px #ffffff, 0 18px 48px rgba(0,0,0,0.45)",
          animation: "wb-knock 1s ease-out",
        }}
      >
        {WB.effects.attention}
      </p>
    </div>
  );
}
