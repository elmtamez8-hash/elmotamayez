"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { GRAPH_COLORS, GraphError, MAX_FUNCTIONS, MAX_POINTS, normaliseGraph, renderGraph, type GraphData } from "@/lib/whiteboard/graph";
import { WB } from "@/lib/whiteboard/strings";

/** The form keeps what is typed (a lone «-» is on its way to a number); the data is read from it. */
type Form = {
  functions: string[];
  x: [string, string];
  y: [string, string] | null;
  points: { x: string; y: string; label: string }[];
  angle: GraphData["angle"];
};

const PREVIEW_DELAY_MS = 400;

function toForm(input: GraphData): Form {
  const data = normaliseGraph(input);
  return {
    functions: data.functions.map((f) => f.expr).slice(0, MAX_FUNCTIONS),
    x: [String(data.x[0]), String(data.x[1])],
    y: data.y ? [String(data.y[0]), String(data.y[1])] : null,
    points: data.points.slice(0, MAX_POINTS).map((p) => ({ x: String(p.x), y: String(p.y), label: p.label })),
    angle: data.angle === "deg" ? "deg" : "rad",
  };
}

function toData(form: Form): GraphData {
  const num = (text: string) => (Number.isFinite(Number.parseFloat(text)) ? Number.parseFloat(text) : 0);
  return {
    kind: "graph",
    v: 1,
    // The colour follows the place: the first function is always blue.
    functions: form.functions.map((expr, i) => ({ expr, color: GRAPH_COLORS[i] })),
    x: [num(form.x[0]), num(form.x[1])],
    y: form.y ? [num(form.y[0]), num(form.y[1])] : null,
    points: form.points.filter((p) => p.x.trim() && p.y.trim()).map((p) => ({ x: num(p.x), y: num(p.y), label: p.label })),
    angle: form.angle,
  };
}

/**
 * The graph's functions, ranges and points, edited — with the picture redrawn
 * as they change, so a mistyped function shows before it reaches the board.
 */
export function GraphEditor({
  initial,
  saving,
  onSave,
  onClose,
}: {
  initial: GraphData;
  saving: boolean;
  onSave: (data: GraphData) => void;
  onClose: () => void;
}) {
  const [form, setForm] = useState(() => toForm(initial));
  const [preview, setPreview] = useState<string | null>(null);
  const [wrong, setWrong] = useState<number | null>(null);

  useEffect(() => {
    let url: string | null = null;
    let stale = false;
    const timer = window.setTimeout(() => {
      renderGraph(toData(form))
        .then(({ blob }) => {
          if (stale) return;
          url = URL.createObjectURL(blob);
          setPreview(url);
          setWrong(null);
        })
        .catch((error: unknown) => {
          if (!stale) setWrong(error instanceof GraphError ? error.index : null);
        });
    }, PREVIEW_DELAY_MS);
    return () => {
      stale = true;
      window.clearTimeout(timer);
      if (url) URL.revokeObjectURL(url);
    };
  }, [form]);

  const change = (next: Partial<Form>) => setForm((f) => ({ ...f, ...next }));
  const field = "w-24 rounded border border-line bg-surface px-2 py-1 text-sm";

  return (
    <div role="dialog" aria-modal="true" aria-label={WB.graph.title} className="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
      <div className="flex max-h-full w-full max-w-5xl flex-col gap-3 overflow-auto rounded-xl bg-surface-raised p-4 text-ink shadow-xl">
        <h2 className="text-lg font-bold">{WB.graph.title}</h2>
        <p className="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
          {WB.graph.hint}
          {WB.graph.examples.map((example) => (
            <code key={example} dir="ltr" className="rounded bg-surface px-1.5 py-0.5 text-ink">{example}</code>
          ))}
        </p>

        <div className="grid gap-4 md:grid-cols-[1fr_1.2fr]">
          <div className="flex flex-col gap-3">
            {form.functions.map((expr, i) => (
              <div key={i} className="flex flex-col gap-1">
                {/* A formula reads left to right: «y = x^2 - 3». */}
                <div className="flex items-center gap-2" dir="ltr">
                  <span aria-hidden className="h-4 w-4 shrink-0 rounded-full" style={{ background: GRAPH_COLORS[i] }} />
                  <span className="shrink-0 font-mono text-sm" dir="ltr">y =</span>
                  <input
                    aria-label={WB.graph.fn(i + 1)}
                    aria-invalid={wrong === i}
                    value={expr}
                    dir="ltr"
                    spellCheck={false}
                    onChange={(event) => change({ functions: form.functions.map((e, j) => (j === i ? event.target.value : e)) })}
                    className="min-w-0 flex-1 rounded border border-line bg-surface px-2 py-1 font-mono text-sm"
                  />
                  {form.functions.length > 1 && (
                    <Button size="sm" variant="ghost" onClick={() => change({ functions: form.functions.filter((_, j) => j !== i) })}>
                      {WB.graph.remove}
                    </Button>
                  )}
                </div>
                {wrong === i && <p role="alert" className="text-xs text-danger">{WB.graph.invalid}</p>}
              </div>
            ))}
            {form.functions.length < MAX_FUNCTIONS && (
              <div>
                <Button size="sm" variant="secondary" onClick={() => change({ functions: [...form.functions, ""] })}>{WB.graph.addFn}</Button>
              </div>
            )}

            <div className="flex flex-wrap items-center gap-2 text-sm" dir="ltr">
              <span dir="rtl">{WB.graph.xRange}</span>
              <input aria-label={`x ${WB.graph.from}`} type="number" step="any" value={form.x[0]} onChange={(e) => change({ x: [e.target.value, form.x[1]] })} className={field} />
              <span>…</span>
              <input aria-label={`x ${WB.graph.to}`} type="number" step="any" value={form.x[1]} onChange={(e) => change({ x: [form.x[0], e.target.value] })} className={field} />
            </div>
            <div className="flex flex-wrap items-center gap-2 text-sm" dir="ltr">
              <span dir="rtl">{WB.graph.yRange}</span>
              {form.y ? (
                <>
                  <input aria-label={`y ${WB.graph.from}`} type="number" step="any" value={form.y[0]} onChange={(e) => change({ y: [e.target.value, form.y![1]] })} className={field} />
                  <span>…</span>
                  <input aria-label={`y ${WB.graph.to}`} type="number" step="any" value={form.y[1]} onChange={(e) => change({ y: [form.y![0], e.target.value] })} className={field} />
                </>
              ) : null}
              <label className="flex items-center gap-1" dir="rtl">
                <input type="checkbox" checked={!form.y} onChange={(e) => change({ y: e.target.checked ? null : ["-5", "5"] })} />
                {WB.graph.yAuto}
              </label>
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={form.angle === "deg"} onChange={(e) => change({ angle: e.target.checked ? "deg" : "rad" })} />
              {WB.graph.degrees}
            </label>

            <div className="flex flex-col gap-2 text-sm">
              <span>{WB.graph.points}</span>
              {form.points.map((p, i) => {
                const set = (next: Partial<Form["points"][number]>) => change({ points: form.points.map((q, j) => (j === i ? { ...q, ...next } : q)) });
                return (
                  <div key={i} className="flex flex-wrap items-center gap-2">
                    <span dir="ltr">(</span>
                    <input aria-label={WB.graph.pointX(i + 1)} type="number" step="any" value={p.x} onChange={(e) => set({ x: e.target.value })} className={field} dir="ltr" />
                    <span>،</span>
                    <input aria-label={WB.graph.pointY(i + 1)} type="number" step="any" value={p.y} onChange={(e) => set({ y: e.target.value })} className={field} dir="ltr" />
                    <span dir="ltr">)</span>
                    <input aria-label={WB.graph.pointLabel(i + 1)} value={p.label} dir="auto" onChange={(e) => set({ label: e.target.value })} className="w-28 rounded border border-line bg-surface px-2 py-1" />
                    <Button size="sm" variant="ghost" onClick={() => change({ points: form.points.filter((_, j) => j !== i) })}>{WB.graph.remove}</Button>
                  </div>
                );
              })}
              {form.points.length < MAX_POINTS && (
                <div>
                  <Button size="sm" variant="secondary" onClick={() => change({ points: [...form.points, { x: "", y: "", label: "" }] })}>{WB.graph.addPoint}</Button>
                </div>
              )}
            </div>
          </div>

          {/* The picture is drawn on white in either theme. */}
          <div className="flex items-start justify-center rounded border border-line p-1" style={{ background: "#ffffff" }}>
            {/* eslint-disable-next-line @next/next/no-img-element -- a blob drawn here, not a page asset */}
            {preview ? <img src={preview} alt={WB.graph.preview} className="h-auto w-full" /> : <div className="aspect-[4/3] w-full" />}
          </div>
        </div>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={saving}>{WB.graph.cancel}</Button>
          <Button onClick={() => onSave(toData(form))} loading={saving} disabled={wrong !== null}>{WB.graph.save}</Button>
        </div>
      </div>
    </div>
  );
}
