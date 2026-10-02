"use client";

import { useEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/Button";
import { arabicNumber } from "@/lib/numerals";
import { playChime } from "@/lib/whiteboard/effect-sounds";
import { pickIndex, spinTo, wheelEntries } from "@/lib/whiteboard/wheel";
import { loadWheel, saveWheel, type WheelPick } from "@/lib/whiteboard/wheel-store";
import { WB } from "@/lib/whiteboard/strings";

/**
 * A wheel that picks a student (or a number) at random (US9), spun in front of
 * the class, with the ORDER of the picks kept so the teacher can ask them in turn
 * (owner's request, 2026-10-02).
 *
 * - The names the teacher typed are never changed by the wheel. «بدون تكرار»
 *   takes the picked ones off the WHEEL only; «جولة جديدة» puts them all back.
 * - Names and picks are kept in this browser for this board (`wheel-store.ts`),
 *   so closing the wheel loses nothing. Never sent to the server.
 *
 * ⚠️ THE DRAWING TURNS INSIDE A FIXED BOX. Turning the whole square <svg> made its
 * corners sweep past its box on every spin, and the panel's scrollbar came and
 * went — the shake the owner saw. The <g> inside turns; the box never changes.
 */

const COLOURS = ["#ff3b30", "#ff9500", "#ffcc00", "#34c759", "#0a84ff", "#5856d6", "#af52de", "#ff2d92"];
const SPIN_MS = 4200;
const R = 200;

export function Wheel({ board, sound, onClose }: { board: string; sound: boolean; onClose: () => void }) {
  const [memory] = useState(() => loadWheel(board));
  const [text, setText] = useState(memory.text);
  const [picks, setPicks] = useState<WheelPick[]>(memory.picks);
  const [noRepeat, setNoRepeat] = useState(true);
  const [angle, setAngle] = useState(0);
  const [spinning, setSpinning] = useState(false);
  const [winner, setWinner] = useState<string | null>(null);
  const result = useRef(0);

  // Closed mid-spin: no chime, no pick recorded that nobody saw.
  useEffect(() => () => window.clearTimeout(result.current), []);
  useEffect(() => saveWheel(board, { text, picks }), [board, text, picks]);

  const names = wheelEntries(text);
  const picked = new Set(picks.map((p) => p.name));
  // Without repeats the wheel shows only those not picked yet this round.
  const onWheel = noRepeat ? names.filter((name) => !picked.has(name)) : names;
  const count = onWheel.length;

  const spin = () => {
    if (count < 2 || spinning) return;
    const index = pickIndex(count);
    const chosen = onWheel[index];
    setWinner(null);
    setSpinning(true);
    setAngle((from) => spinTo(from, index, count));
    result.current = window.setTimeout(() => {
      setSpinning(false);
      setWinner(chosen);
      setPicks((list) => [...list, { name: chosen, at: Date.now() }]);
      if (sound) playChime();
    }, SPIN_MS);
  };

  // The last one left needs no spin.
  const takeLast = () => {
    if (count !== 1 || spinning) return;
    setWinner(onWheel[0]);
    setPicks((list) => [...list, { name: onWheel[0], at: Date.now() }]);
  };

  const segment = count > 0 ? 360 / count : 360;
  const point = (deg: number, radius: number) => {
    const rad = ((deg - 90) * Math.PI) / 180;
    return `${R + radius * Math.cos(rad)},${R + radius * Math.sin(rad)}`;
  };

  return (
    <div className="absolute inset-0 flex items-center justify-center bg-black/40" style={{ zIndex: 7 }} data-effect="wheel">
      <div className="flex max-h-full flex-wrap items-start justify-center gap-6 overflow-auto rounded-2xl bg-surface-raised p-5 text-ink shadow-xl">
        <div className="relative shrink-0 overflow-hidden" style={{ width: R * 2, height: R * 2 + 16, paddingTop: 16 }}>
          {/* The pointer, at the top. */}
          <div aria-hidden className="absolute top-0 z-10" style={{ left: "calc(50% - 16px)", width: 0, height: 0, borderLeft: "16px solid transparent", borderRight: "16px solid transparent", borderTop: "28px solid #111827" }} />
          <svg viewBox={`0 0 ${R * 2} ${R * 2}`} width={R * 2} height={R * 2} aria-hidden>
            <g
              data-wheel-angle={angle}
              style={{
                transformOrigin: `${R}px ${R}px`,
                transform: `rotate(${angle}deg)`,
                transition: spinning ? `transform ${SPIN_MS}ms cubic-bezier(0.15, 0.85, 0.25, 1)` : "none",
              }}
            >
              {count === 0 && <circle cx={R} cy={R} r={R - 2} fill="#e5e7eb" />}
              {count === 1 && <circle cx={R} cy={R} r={R - 2} fill={COLOURS[0]} />}
              {count > 1 &&
                onWheel.map((entry, i) => {
                  const from = i * segment;
                  const to = from + segment;
                  const large = segment > 180 ? 1 : 0;
                  const mid = from + segment / 2;
                  return (
                    <g key={`${entry}-${i}`}>
                      <path d={`M${R},${R} L${point(from, R - 2)} A${R - 2},${R - 2} 0 ${large} 1 ${point(to, R - 2)} Z`} fill={COLOURS[i % COLOURS.length]} stroke="#fff" strokeWidth={2} />
                      <text x={R} y={R - R * 0.62} transform={`rotate(${mid} ${R} ${R})`} textAnchor="middle" dominantBaseline="middle" fill="#fff" fontWeight={700} fontSize={count > 16 ? 13 : 18}>
                        {entry.length > 14 ? `${entry.slice(0, 13)}…` : entry}
                      </text>
                    </g>
                  );
                })}
              {count === 1 && (
                <text x={R} y={R} textAnchor="middle" dominantBaseline="middle" fill="#fff" fontWeight={700} fontSize={28}>
                  {onWheel[0]}
                </text>
              )}
            </g>
          </svg>
        </div>

        <div className="flex w-64 flex-col gap-3">
          <label className="flex flex-col gap-1 text-sm">
            {WB.tools.wheelNames}
            <textarea
              value={text}
              onChange={(event) => setText(event.target.value)}
              rows={6}
              dir="rtl"
              disabled={spinning}
              placeholder={WB.tools.wheelPlaceholder}
              className="rounded-lg border border-line bg-surface p-2 text-ink"
            />
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={noRepeat} onChange={(event) => setNoRepeat(event.target.checked)} disabled={spinning} />
            {WB.tools.wheelNoRepeat}
          </label>
          {count === 1 ? (
            <Button onClick={takeLast}>{WB.tools.wheelLast(onWheel[0])}</Button>
          ) : (
            <Button onClick={spin} disabled={count < 2 || spinning}>
              {WB.tools.spin}
            </Button>
          )}
          <p role="status" aria-live="polite" className="min-h-12 text-center text-3xl font-extrabold">
            {winner}
          </p>

          <section aria-label={WB.tools.wheelOrder} className="flex flex-col gap-1">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold">{WB.tools.wheelOrder}</h3>
              <Button size="sm" variant="ghost" disabled={spinning || picks.length === 0} onClick={() => { setPicks([]); setWinner(null); }}>
                {WB.tools.wheelNewRound}
              </Button>
            </div>
            {picks.length === 0 ? (
              <p className="text-xs text-ink-muted">{WB.tools.wheelNoPicks}</p>
            ) : (
              <ol className="max-h-40 overflow-y-auto text-sm" data-wheel-order>
                {picks.map((pick, i) => (
                  <li key={`${pick.at}-${i}`} className="flex gap-2">
                    <span className="tabular-nums text-ink-muted">{arabicNumber(i + 1)}.</span>
                    <span>{pick.name}</span>
                  </li>
                ))}
              </ol>
            )}
          </section>

          <Button variant="ghost" onClick={onClose}>
            {WB.tools.closeWheel}
          </Button>
        </div>
      </div>
    </div>
  );
}
