"use client";

import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from "react";

import { calculate, type AngleUnit, type CalcResult } from "@/lib/whiteboard/calculator";
import { WB } from "@/lib/whiteboard/strings";

type Field = HTMLElement & {
  value: string;
  executeCommand: (command: unknown) => boolean;
  focus: () => void;
  mathVirtualKeyboardPolicy?: string;
  readOnly?: boolean;
};

interface Entry {
  input: string;
  result: Extract<CalcResult, { ok: true }>;
}

/** A key: what it shows, and what it types into the field (or does). */
type Key = { label: string; insert?: string; act?: "del" | "ac" | "ans" | "equals" | "left" | "right"; wide?: boolean; tone?: "op" | "fn" | "eq" };

const KEYS: Key[][] = [
  // The Casio's replay pad: out of a fraction's denominator, back into an exponent.
  [
    { label: "◀", act: "left", wide: true, tone: "op" },
    { label: "▶", act: "right", wide: true, tone: "op" },
    { label: "(", insert: "(" },
    { label: ")", insert: ")" },
  ],
  [
    { label: "x²", insert: "#@^{2}", tone: "fn" },
    { label: "xⁿ", insert: "#@^{#?}", tone: "fn" },
    { label: "√", insert: "\\sqrt{#0}", tone: "fn" },
    { label: "ⁿ√", insert: "\\sqrt[#?]{#0}", tone: "fn" },
    { label: "▭⁄▭", insert: "\\frac{#@}{#?}", tone: "fn" },
    { label: "x!", insert: "#@!", tone: "fn" },
  ],
  [
    { label: "sin", insert: "\\sin\\left(#0\\right)", tone: "fn" },
    { label: "cos", insert: "\\cos\\left(#0\\right)", tone: "fn" },
    { label: "tan", insert: "\\tan\\left(#0\\right)", tone: "fn" },
    { label: "log", insert: "\\log\\left(#0\\right)", tone: "fn" },
    { label: "ln", insert: "\\ln\\left(#0\\right)", tone: "fn" },
    { label: "π", insert: "\\pi", tone: "fn" },
  ],
  [
    { label: "7", insert: "7" },
    { label: "8", insert: "8" },
    { label: "9", insert: "9" },
    { label: "e", insert: "e", tone: "fn" },
    { label: "x⁻¹", insert: "#@^{-1}", tone: "fn" },
    { label: "(−)", insert: "-", tone: "fn" },
  ],
  [
    { label: "4", insert: "4" },
    { label: "5", insert: "5" },
    { label: "6", insert: "6" },
    { label: "×", insert: "\\times", tone: "op" },
    { label: "÷", insert: "\\div", tone: "op" },
    { label: "DEL", act: "del", tone: "eq" },
  ],
  [
    { label: "1", insert: "1" },
    { label: "2", insert: "2" },
    { label: "3", insert: "3" },
    { label: "+", insert: "+", tone: "op" },
    { label: "−", insert: "-", tone: "op" },
    { label: "AC", act: "ac", tone: "eq" },
  ],
  [
    { label: "0", insert: "0" },
    { label: ".", insert: "." },
    { label: "×10ˣ", insert: "\\times10^{#?}" },
    { label: "Ans", act: "ans" },
    { label: "=", act: "equals", wide: true, tone: "eq" },
  ],
];

/**
 * «آلة حاسبة» — a floating scientific calculator on the board, shaped like the
 * Casio a student has in their bag. Fixed colours, like the timer: the class
 * watches it through the stream on any board colour. Dragged by its top bar.
 */
export function Calculator({ onInsert, onClose }: { onInsert: ((latex: string) => void) | null; onClose: () => void }) {
  const host = useRef<HTMLDivElement>(null);
  const output = useRef<HTMLDivElement>(null);
  const field = useRef<Field | null>(null);
  const shown = useRef<Field | null>(null);
  const [angle, setAngle] = useState<AngleUnit>("deg");
  const [answer, setAnswer] = useState<Entry | null>(null);
  const [error, setError] = useState<"syntax" | "math" | null>(null);
  const [decimal, setDecimal] = useState(false);
  const [history, setHistory] = useState<Entry[]>([]);
  const [showHistory, setShowHistory] = useState(false);
  const [busy, setBusy] = useState(false);
  const [place, setPlace] = useState({ x: 24, y: 96 });
  const drag = useRef<{ dx: number; dy: number } | null>(null);
  const equalsRef = useRef<() => void>(() => undefined);

  // The input and the answer are MathLive fields: fractions and roots drawn as on paper.
  useEffect(() => {
    let alive = true;
    void import("mathlive").then(({ MathfieldElement }) => {
      if (!alive || !host.current || !output.current) return;
      MathfieldElement.fontsDirectory = `${window.location.origin}/mathlive/fonts`;
      MathfieldElement.soundsDirectory = null;
      const input = new MathfieldElement() as unknown as Field;
      input.mathVirtualKeyboardPolicy = "manual"; // the keys below are the keyboard
      input.setAttribute("aria-label", WB.calc.input);
      input.style.cssText = "width:100%;font-size:26px;background:transparent;color:#0f172a;border:none;outline:none";
      input.addEventListener("keydown", (event) => {
        if ((event as KeyboardEvent).key === "Enter") {
          event.preventDefault();
          equalsRef.current();
        }
      });
      host.current.replaceChildren(input);
      field.current = input;
      const result = new MathfieldElement() as unknown as Field;
      result.readOnly = true;
      result.setAttribute("aria-label", WB.calc.result);
      result.style.cssText = "width:100%;font-size:30px;background:transparent;color:#0f172a;border:none;text-align:right";
      output.current.replaceChildren(result);
      shown.current = result;
      input.focus();
    });
    return () => {
      alive = false;
    };
  }, []);

  const resultLatex = (entry: Entry, asDecimal: boolean) => (asDecimal || entry.result.exact === null ? entry.result.decimal : entry.result.exact);

  useEffect(() => {
    if (shown.current) shown.current.value = answer ? resultLatex(answer, decimal) : "";
  }, [answer, decimal]);

  const equals = async () => {
    const input = field.current?.value ?? "";
    setBusy(true);
    try {
      const result = await calculate(input, angle);
      if (!result.ok) {
        setError(result.error);
        setAnswer(null);
        return;
      }
      const entry = { input, result };
      setError(null);
      setDecimal(false);
      setAnswer(entry);
      setHistory((all) => [entry, ...all].slice(0, 10));
    } catch {
      setError("math");
    } finally {
      setBusy(false);
    }
  };
  equalsRef.current = () => void equals();

  const press = (key: Key) => {
    const input = field.current;
    if (!input) return;
    if (key.act === "equals") return void equals();
    if (key.act === "del") input.executeCommand("deleteBackward");
    else if (key.act === "left") input.executeCommand("moveToPreviousChar");
    else if (key.act === "right") input.executeCommand("moveToNextChar");
    else if (key.act === "ac") {
      input.value = "";
      setAnswer(null);
      setError(null);
    } else if (key.act === "ans") {
      if (history[0]) input.executeCommand(["insert", `\\left(${resultLatex(history[0], false)}\\right)`]);
    } else if (key.insert) input.executeCommand(["insert", key.insert]);
    input.focus();
  };

  const onGrab = (event: ReactPointerEvent<HTMLDivElement>) => {
    if ((event.target as HTMLElement).closest("button")) return;
    drag.current = { dx: event.clientX - place.x, dy: event.clientY - place.y };
    event.currentTarget.setPointerCapture(event.pointerId);
  };
  const onMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (drag.current) setPlace({ x: event.clientX - drag.current.dx, y: event.clientY - drag.current.dy });
  };

  const keyStyle = (key: Key) =>
    key.tone === "eq" ? { background: "#f97316", color: "#fff" } : key.tone === "op" ? { background: "#334155", color: "#fff" } : key.tone === "fn" ? { background: "#1e293b", color: "#e2e8f0" } : { background: "#475569", color: "#fff" };

  return (
    <div
      role="dialog"
      aria-label={WB.calc.title}
      dir="ltr"
      data-effect="calculator"
      className="absolute w-[340px] select-none rounded-3xl p-3 shadow-2xl"
      style={{ left: place.x, top: place.y, zIndex: 7, background: "#0f172a", color: "#fff" }}
    >
      <div className="mb-2 flex cursor-move items-center justify-between gap-2" onPointerDown={onGrab} onPointerMove={onMove} onPointerUp={() => (drag.current = null)}>
        <span className="text-sm font-bold" dir="rtl">
          {WB.calc.title}
        </span>
        <div className="flex gap-1 text-xs">
          <button type="button" className="rounded-md bg-white/15 px-2 py-1 font-semibold" onClick={() => setAngle((a) => (a === "deg" ? "rad" : "deg"))} title={WB.calc.angle}>
            {angle === "deg" ? "DEG" : "RAD"}
          </button>
          <button type="button" className="rounded-md bg-white/15 px-2 py-1" aria-pressed={showHistory} onClick={() => setShowHistory((s) => !s)}>
            {WB.calc.history}
          </button>
          <button type="button" className="rounded-md bg-white/15 px-2 py-1" onClick={onClose} aria-label={WB.calc.close}>
            ✕
          </button>
        </div>
      </div>

      <div className="rounded-xl p-2" style={{ background: "#d9e4d0", minHeight: 96 }}>
        <div ref={host} />
        <div className="flex items-center gap-2">
          <div ref={output} className="flex-1" style={{ display: error ? "none" : undefined }} />
          {error && (
            <p role="alert" className="flex-1 text-right text-lg font-bold" style={{ color: "#b91c1c" }} dir="rtl">
              {error === "syntax" ? WB.calc.syntaxError : WB.calc.mathError}
            </p>
          )}
        </div>
      </div>

      {showHistory && (
        <ol className="mt-2 max-h-40 space-y-1 overflow-auto rounded-xl bg-white/10 p-2 text-sm" aria-label={WB.calc.history}>
          {history.length === 0 && (
            <li dir="rtl" className="text-white/70">
              {WB.calc.noHistory}
            </li>
          )}
          {history.map((entry, index) => (
            <li key={index}>
              <button
                type="button"
                className="w-full truncate rounded px-1 text-left hover:bg-white/10"
                onClick={() => {
                  if (field.current) field.current.value = entry.input;
                  setAnswer(entry);
                  setError(null);
                }}
              >
                {entry.input} = {resultLatex(entry, false)}
              </button>
            </li>
          ))}
        </ol>
      )}

      <div className="mt-2 flex gap-2 text-sm">
        <button
          type="button"
          disabled={!answer || answer.result.exact === null}
          className="flex-1 rounded-lg bg-white/15 py-1.5 font-semibold disabled:opacity-40"
          onClick={() => setDecimal((d) => !d)}
          title={WB.calc.toggleForm}
        >
          S⇔D
        </button>
        {onInsert && (
          <button
            type="button"
            disabled={!answer}
            className="flex-[2] rounded-lg bg-white/15 py-1.5 font-semibold disabled:opacity-40"
            dir="rtl"
            onClick={() => answer && onInsert(`${answer.input}=${resultLatex(answer, decimal)}`)}
          >
            {WB.calc.insert}
          </button>
        )}
      </div>

      <div className="mt-2 grid grid-cols-6 gap-1.5">
        {KEYS.flat().map((key) => (
          <button
            key={key.label}
            type="button"
            disabled={busy && key.act === "equals"}
            className={`rounded-lg py-2 text-base font-semibold active:scale-95 ${key.wide ? "col-span-2" : ""}`}
            style={keyStyle(key)}
            onClick={() => press(key)}
          >
            {key.label}
          </button>
        ))}
      </div>
    </div>
  );
}
