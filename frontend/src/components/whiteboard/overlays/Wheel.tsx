"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { playChime } from "@/lib/whiteboard/effect-sounds";
import { pickIndex, spinTo, wheelEntries } from "@/lib/whiteboard/wheel";
import { WB } from "@/lib/whiteboard/strings";

/**
 * A wheel that picks a student (or a number) at random (US9), spun in front of
 * the class. The names live in this window only — never saved, never sent: they
 * are students' names, and the wheel has no reason to keep them.
 */

const COLOURS = ["#ff3b30", "#ff9500", "#ffcc00", "#34c759", "#0a84ff", "#5856d6", "#af52de", "#ff2d92"];
const SPIN_MS = 4200;
const R = 200;

export function Wheel({ sound, onClose }: { sound: boolean; onClose: () => void }) {
  const [text, setText] = useState("");
  const [angle, setAngle] = useState(0);
  const [spinning, setSpinning] = useState(false);
  const [winner, setWinner] = useState<string | null>(null);
  const [removeWinner, setRemoveWinner] = useState(false);
  const entries = wheelEntries(text);
  const count = entries.length;

  const spin = () => {
    if (count < 2 || spinning) return;
    const index = pickIndex(count);
    setWinner(null);
    setSpinning(true);
    setAngle((from) => spinTo(from, index, count));
    window.setTimeout(() => {
      setSpinning(false);
      setWinner(entries[index]);
      if (sound) playChime();
      if (removeWinner) setText(entries.filter((_, i) => i !== index).join("\n"));
    }, SPIN_MS);
  };

  const segment = count > 0 ? 360 / count : 360;
  const point = (deg: number, radius: number) => {
    const rad = ((deg - 90) * Math.PI) / 180;
    return `${R + radius * Math.cos(rad)},${R + radius * Math.sin(rad)}`;
  };

  return (
    <div className="absolute inset-0 flex items-center justify-center bg-black/40" style={{ zIndex: 7 }} data-effect="wheel">
      <div className="flex max-h-full flex-wrap items-center justify-center gap-6 overflow-auto rounded-2xl bg-surface-raised p-5 text-ink shadow-xl">
        <div className="relative" style={{ width: R * 2, height: R * 2 }}>
          {/* The pointer, at the top. */}
          <div aria-hidden className="absolute top-[-14px] z-10" style={{ left: "calc(50% - 16px)", width: 0, height: 0, borderLeft: "16px solid transparent", borderRight: "16px solid transparent", borderTop: "28px solid #111827" }} />
          <svg
            viewBox={`0 0 ${R * 2} ${R * 2}`}
            width={R * 2}
            height={R * 2}
            aria-hidden
            style={{ transform: `rotate(${angle}deg)`, transition: spinning ? `transform ${SPIN_MS}ms cubic-bezier(0.15, 0.85, 0.25, 1)` : "none" }}
          >
            {count === 0 && <circle cx={R} cy={R} r={R - 2} fill="#e5e7eb" />}
            {entries.map((entry, i) => {
              const from = i * segment;
              const to = from + segment;
              const large = segment > 180 ? 1 : 0;
              const mid = from + segment / 2;
              return (
                <g key={`${entry}-${i}`}>
                  <path d={`M${R},${R} L${point(from, R - 2)} A${R - 2},${R - 2} 0 ${large} 1 ${point(to, R - 2)} Z`} fill={COLOURS[i % COLOURS.length]} stroke="#fff" strokeWidth={2} />
                  <text
                    x={R}
                    y={R - R * 0.62}
                    transform={`rotate(${mid} ${R} ${R})`}
                    textAnchor="middle"
                    dominantBaseline="middle"
                    fill="#fff"
                    fontWeight={700}
                    fontSize={count > 16 ? 13 : 18}
                  >
                    {entry.length > 14 ? `${entry.slice(0, 13)}…` : entry}
                  </text>
                </g>
              );
            })}
          </svg>
        </div>

        <div className="flex w-64 flex-col gap-3">
          <label className="flex flex-col gap-1 text-sm">
            {WB.tools.wheelNames}
            <textarea
              value={text}
              onChange={(event) => setText(event.target.value)}
              rows={7}
              dir="rtl"
              disabled={spinning}
              placeholder={WB.tools.wheelPlaceholder}
              className="rounded-lg border border-line bg-surface p-2 text-ink"
            />
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={removeWinner} onChange={(event) => setRemoveWinner(event.target.checked)} />
            {WB.tools.wheelRemove}
          </label>
          <Button onClick={spin} disabled={count < 2 || spinning}>
            {WB.tools.spin}
          </Button>
          <p role="status" aria-live="polite" className="min-h-12 text-center text-3xl font-extrabold">
            {winner}
          </p>
          <Button variant="ghost" onClick={onClose}>
            {WB.tools.closeWheel}
          </Button>
        </div>
      </div>
    </div>
  );
}
