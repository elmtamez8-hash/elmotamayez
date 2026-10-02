"use client";

import { useEffect, useRef } from "react";

import { STUNT_MS, type Stunt as StuntKind } from "@/lib/whiteboard/effects";
import { WB } from "@/lib/whiteboard/strings";

/**
 * ⚠️ A figure centred by `centre` gets its `translate(-50%, -50%)` from its
 * keyframes, so its LAST animation must keep `forwards` — without it the figure
 * jumps by half its size the moment the motion ends (caught in review).
 *
 * One large figure acting a short scene over the board — a plane dropping a
 * gift, an egg or a tomato splatting on the «glass», a brick cracking it, a
 * whistle, the teacher's stick, a warning, a «wrong», a yellow or a red card.
 * The reference board sells these; ours are drawn here (CSS + SVG), free.
 *
 * ⚠️ NO EXCALIDRAW API IS HANDED IN (FR-032, SC-010): `pointer-events: none`,
 * gone after `STUNT_MS`; nothing reaches the page or an export. Sized for the
 * stream: every figure is at least a fifth of the screen's height.
 */
export function Stunt({ kind, onDone }: { kind: StuntKind; onDone: () => void }) {
  const doneRef = useRef(onDone);
  doneRef.current = onDone;

  useEffect(() => {
    const finish = window.setTimeout(() => doneRef.current(), STUNT_MS);
    return () => window.clearTimeout(finish);
  }, [kind]);

  return (
    <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden" style={{ zIndex: 6 }} data-stunt={kind}>
      <style>{KEYFRAMES}</style>
      {SCENES[kind]}
    </div>
  );
}

const MS = STUNT_MS / 1000;
const centre = "absolute left-1/2 top-1/2";

const KEYFRAMES = `
  @keyframes wb-fly { from { transform: translateX(60vw) translateY(-50%); } to { transform: translateX(-160vw) translateY(-50%); } }
  @keyframes wb-drop { 0% { transform: translate(-50%, -40vh) scale(.6); opacity: 0; } 15% { opacity: 1; }
    70% { transform: translate(-50%, -50%) scale(1.15); } 80% { transform: translate(-50%, -58%) scale(1); }
    100% { transform: translate(-50%, -50%) scale(1.25); opacity: 1; } }
  @keyframes wb-throw { 0% { transform: translate(-50%, 55vh) scale(.35) rotate(0); opacity: 1; }
    100% { transform: translate(-50%, -50%) scale(1.3) rotate(540deg); opacity: 1; } }
  @keyframes wb-splat { 0% { transform: translate(-50%, -50%) scale(0); opacity: 1; } 8% { transform: translate(-50%, -50%) scale(1.1); }
    14%, 75% { transform: translate(-50%, -50%) scale(1); opacity: 1; } 100% { transform: translate(-50%, -46%) scale(1, 1.08); opacity: 0; } }
  @keyframes wb-shake { 0%, 100% { transform: translate(-50%, -50%); } 10%, 30%, 50%, 70% { transform: translate(-54%, -50%) rotate(-4deg); }
    20%, 40%, 60%, 80% { transform: translate(-46%, -50%) rotate(4deg); } }
  @keyframes wb-pulse { 0%, 100% { transform: translate(-50%, -50%) scale(1); } 50% { transform: translate(-50%, -50%) scale(1.15); } }
  @keyframes wb-pop-in { from { transform: translate(-50%, -50%) scale(0); } to { transform: translate(-50%, -50%) scale(1); } }
  @keyframes wb-card { 0% { transform: translate(-50%, 70vh) rotate(-12deg); } 20%, 80% { transform: translate(-50%, -50%) rotate(-6deg); }
    100% { transform: translate(-50%, 70vh) rotate(-12deg); } }
  @keyframes wb-tap { 0%, 30%, 60%, 90% { transform: rotate(-38deg); } 15%, 45%, 75% { transform: rotate(0deg); } 100% { transform: rotate(-38deg); } }
  @keyframes wb-blow { 0%, 100% { transform: translate(-50%, -50%) scale(1); } 20%, 60% { transform: translate(-50%, -50%) scale(1.08) rotate(-3deg); }
    40%, 80% { transform: translate(-50%, -50%) scale(1.04) rotate(3deg); } }
  @keyframes wb-jolt { 0%, 100% { transform: rotate(0); } 25%, 75% { transform: rotate(-3deg); } 50% { transform: rotate(3deg); } }
  @keyframes wb-fade-out { 0%, 80% { opacity: 1; } 100% { opacity: 0; } }
`;

/** A thrown thing landing on the «glass» at the centre, then the mark it leaves. */
function Thrown({ glyph, mark }: { glyph: string; mark: React.ReactNode }) {
  return (
    <>
      <span className={`${centre} text-[18vh] leading-none`} style={{ animation: `wb-throw .55s ease-in forwards, wb-fade-out .01s linear .55s forwards` }}>
        {glyph}
      </span>
      <div className={centre} style={{ animation: `wb-splat ${MS - 0.55}s ease-out .55s both` }}>
        {mark}
      </div>
    </>
  );
}

/** An irregular splat: one blob and a ring of drops. */
function Splat({ fill, centreFill }: { fill: string; centreFill?: string }) {
  return (
    <svg width="46vh" height="46vh" viewBox="-100 -100 200 200">
      <path
        fill={fill}
        d="M0-70C18-72 22-52 38-56 58-60 52-34 66-24 82-12 62 4 70 20 78 38 52 40 44 56 36 74 16 58 0 70-18 82-28 60-44 58-64 56-56 34-68 18-82 0-62-12-66-28-70-48-46-50-34-62-22-74-14-68 0-70Z"
      />
      {[
        [-82, -60, 9],
        [86, -44, 7],
        [78, 66, 10],
        [-70, 74, 7],
        [12, -92, 6],
        [-94, 14, 6],
      ].map(([x, y, r]) => (
        <circle key={`${x}${y}`} cx={x} cy={y} r={r} fill={fill} />
      ))}
      {centreFill && <circle cx="4" cy="2" r="26" fill={centreFill} />}
    </svg>
  );
}

/** Cracks running out from where the brick hit. */
function Crack() {
  const rays = [0, 40, 95, 150, 205, 250, 300, 335];
  return (
    <svg width="70vh" height="70vh" viewBox="-100 -100 200 200" style={{ animation: "wb-jolt .4s linear .55s" }}>
      {rays.map((deg) => {
        const a = (deg * Math.PI) / 180;
        const mid = 40;
        const bend = 0.35;
        return (
          <polyline
            key={deg}
            points={`0,0 ${Math.cos(a + bend) * mid},${Math.sin(a + bend) * mid} ${Math.cos(a) * 95},${Math.sin(a) * 95}`}
            fill="none"
            stroke="#94a3b8"
            strokeWidth="2.5"
            strokeLinejoin="round"
          />
        );
      })}
      <circle r="14" fill="none" stroke="#94a3b8" strokeWidth="2.5" />
    </svg>
  );
}

const SCENES: Record<StuntKind, React.ReactNode> = {
  airplane: (
    <>
      <span className="absolute left-1/2 top-[18%] text-[16vh] leading-none" style={{ animation: `wb-fly ${MS * 0.75}s linear forwards` }}>
        ✈️
      </span>
      <span className={`${centre} text-[20vh] leading-none`} style={{ animation: `wb-drop ${MS * 0.6}s ease-in ${MS * 0.3}s both` }}>
        🎁
      </span>
    </>
  ),
  egg: <Thrown glyph="🥚" mark={<Splat fill="#fef9ec" centreFill="#fbbf24" />} />,
  tomato: <Thrown glyph="🍅" mark={<Splat fill="#dc2626" />} />,
  brick: <Thrown glyph="🧱" mark={<Crack />} />,
  whistle: (
    <svg className={centre} width="46vh" height="28vh" viewBox="-30 0 230 140" style={{ animation: `wb-blow .5s ease-in-out 3 forwards` }}>
      <rect x="10" y="40" width="70" height="26" rx="6" fill="#64748b" />
      <circle cx="120" cy="80" r="52" fill="#94a3b8" />
      <circle cx="120" cy="80" r="20" fill="#475569" />
      <rect x="60" y="34" width="60" height="22" rx="4" fill="#94a3b8" />
      <path d="M6 20 L-20 6 M6 52 L-26 52 M6 84 L-20 98" stroke="#f59e0b" strokeWidth="6" strokeLinecap="round" />
    </svg>
  ),
  stick: (
    <div className="absolute bottom-[8%] left-1/2 h-[60vh] w-[2.2vh] origin-bottom rounded-full" style={{ background: "linear-gradient(90deg,#92400e,#b45309,#78350f)", animation: `wb-tap 1.35s ease-in-out .2s both` }} />
  ),
  warning: (
    <div className={`${centre} flex flex-col items-center`} style={{ animation: `wb-pop-in .25s ease-out, wb-pulse .6s ease-in-out .25s 4 forwards` }}>
      <span className="text-[28vh] leading-none">⚠️</span>
      <span className="rounded-xl px-6 py-1 text-[6vh] font-bold" style={{ background: "#facc15", color: "#1c1917" }}>
        {WB.effects.warning}
      </span>
    </div>
  ),
  wrong: (
    <span className={`${centre} text-[34vh] leading-none`} style={{ animation: `wb-pop-in .2s ease-out, wb-shake .5s linear .2s 2 forwards` }}>
      ❌
    </span>
  ),
  yellowCard: <div className={`${centre} h-[42vh] w-[29vh] rounded-[2vh] shadow-2xl`} style={{ background: "#facc15", animation: `wb-card ${MS}s ease-in-out forwards` }} />,
  redCard: <div className={`${centre} h-[42vh] w-[29vh] rounded-[2vh] shadow-2xl`} style={{ background: "#dc2626", animation: `wb-card ${MS}s ease-in-out forwards` }} />,
};
