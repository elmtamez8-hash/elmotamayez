"use client";

import { useEffect, useMemo, useState } from "react";

import { playPop } from "@/lib/whiteboard/effect-sounds";
import { WB } from "@/lib/whiteboard/strings";

/**
 * Balloons rising across the board that the teacher pops by pressing them (US10,
 * an idea from the reference board the owner chose). Each balloon is a button —
 * the stage around them lets every press through to the pen. Over by itself once
 * the last balloon is popped or has floated away.
 */

const COLOURS = ["#ff3b30", "#ffcc00", "#34c759", "#0a84ff", "#ff2d92", "#ff9500", "#af52de"];
export const BALLOON_COUNT = 12;
/** The slowest balloon is off the top by then. */
export const BALLOONS_MS = 13_000;

interface Balloon {
  id: number;
  left: number;
  delay: number;
  duration: number;
  colour: string;
  size: number;
}

export function BalloonPop({ onDone, sound }: { onDone: () => void; sound: boolean }) {
  const balloons = useMemo<Balloon[]>(
    () =>
      Array.from({ length: BALLOON_COUNT }, (_, id) => ({
        id,
        left: 5 + Math.random() * 85,
        delay: Math.random() * 3,
        duration: 7 + Math.random() * 3,
        colour: COLOURS[id % COLOURS.length],
        size: 70 + Math.random() * 40,
      })),
    [],
  );
  const [popped, setPopped] = useState<Set<number>>(new Set());

  useEffect(() => {
    const end = window.setTimeout(onDone, BALLOONS_MS);
    return () => window.clearTimeout(end);
  }, [onDone]);

  useEffect(() => {
    if (popped.size === BALLOON_COUNT) {
      const end = window.setTimeout(onDone, 400); // let the last burst show
      return () => window.clearTimeout(end);
    }
  }, [popped, onDone]);

  const pop = (id: number) => {
    if (sound) playPop();
    setPopped((done) => new Set(done).add(id));
  };

  return (
    <div className="pointer-events-none absolute inset-0 overflow-hidden" style={{ zIndex: 6 }} data-effect="balloons">
      <style>{`
        @keyframes wb-rise { from { transform: translateY(0); } to { transform: translateY(calc(-100dvh - 220px)); } }
        @keyframes wb-sway { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(24px); } }
        @keyframes wb-burst { to { transform: scale(1.8); opacity: 0; } }
      `}</style>
      {balloons.map((b) => (
        <button
          key={b.id}
          type="button"
          aria-label={WB.effects.popBalloon}
          onClick={() => pop(b.id)}
          disabled={popped.has(b.id)}
          className="pointer-events-auto absolute cursor-pointer border-0 bg-transparent p-0"
          style={{
            left: `${b.left}%`,
            bottom: -b.size * 1.6,
            width: b.size,
            height: b.size * 1.5,
            animation: `wb-rise ${b.duration}s linear ${b.delay}s forwards`,
            visibility: popped.has(b.id) ? "hidden" : undefined,
          }}
          data-balloon={b.id}
        >
          {/* The sway is a transform on the picture, not a margin: a margin re-laid out twelve balloons every frame. */}
          <svg viewBox="0 0 60 90" width="100%" height="100%" aria-hidden style={{ animation: `wb-sway 2.5s ease-in-out ${b.delay}s infinite` }}>
            <ellipse cx="30" cy="30" rx="26" ry="30" fill={b.colour} />
            <ellipse cx="21" cy="20" rx="6" ry="9" fill="#fff" opacity="0.45" />
            <path d="M27 59 L33 59 L30 64 Z" fill={b.colour} />
            <path d="M30 64 Q26 74 31 82 T30 90" stroke="rgba(0,0,0,0.45)" strokeWidth="1.5" fill="none" />
          </svg>
        </button>
      ))}
    </div>
  );
}
