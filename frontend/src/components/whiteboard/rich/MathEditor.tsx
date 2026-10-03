"use client";

import { useEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/Button";
import { mathSvg } from "@/lib/whiteboard/math";
import type { RichData } from "@/lib/whiteboard/excalidraw-api";
import { WB } from "@/lib/whiteboard/strings";

type MathData = Extract<RichData, { kind: "math" }>;

/**
 * An equation, written (story 6): MathLive's field for those who do not know
 * LaTeX (MIT, owner-approved 2026-10-03, loaded only here), the LaTeX itself
 * beside it for those who do — and for chemistry, `\ce{…}`, which MathLive does
 * not draw — and MathJax's drawing of it, exactly as it will land on the board.
 */
export function MathEditor({
  initial,
  saving,
  onSave,
  onClose,
}: {
  initial: MathData;
  saving: boolean;
  onSave: (data: MathData) => void;
  onClose: () => void;
}) {
  const [latex, setLatex] = useState(initial.latex);
  const [display, setDisplay] = useState(initial.display);
  const [preview, setPreview] = useState<{ src: string } | { error: true } | null>(null);
  const host = useRef<HTMLDivElement>(null);
  const field = useRef<(HTMLElement & { value: string }) | null>(null);

  // The visual field: MathLive is fetched the first time an equation is edited.
  useEffect(() => {
    let alive = true;
    void import("mathlive").then(({ MathfieldElement }) => {
      if (!alive || !host.current) return;
      MathfieldElement.fontsDirectory = `${window.location.origin}/mathlive/fonts`; // self-hosted: the CSP allows no font CDN
      MathfieldElement.soundsDirectory = null;
      const mf = new MathfieldElement();
      mf.value = initial.latex;
      mf.setAttribute("aria-label", WB.math.field);
      // MathLive does not draw \ce{…}: chemistry is written in the LaTeX box, never overwritten from the field.
      mf.addEventListener("input", () => setLatex((current) => (current.includes(String.raw`\ce`) ? current : mf.value)));
      host.current.replaceChildren(mf);
      field.current = mf;
    });
    return () => {
      alive = false;
    };
  }, [initial.latex]);

  // What will land on the board, drawn by MathJax — as an <img>, never as markup.
  useEffect(() => {
    if (!latex.trim()) {
      setPreview(null);
      return;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      mathSvg(latex, display)
        .then((svg) => {
          if (!alive) return;
          setPreview(svg.includes("data-mjx-error") ? { error: true } : { src: `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}` });
        })
        .catch(() => alive && setPreview({ error: true }));
    }, 250);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [latex, display]);

  const typed = (value: string) => {
    setLatex(value);
    if (field.current && field.current.value !== value) field.current.value = value;
  };

  const ok = latex.trim() !== "" && preview !== null && !("error" in preview);

  return (
    <div role="dialog" aria-modal="true" aria-label={WB.math.title} className="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
      <div className="flex max-h-full w-full max-w-2xl flex-col gap-3 overflow-auto rounded-xl bg-surface-raised p-4 text-ink shadow-xl">
        <h2 className="text-lg font-bold">{WB.math.title}</h2>

        <div ref={host} dir="ltr" className="min-h-14 rounded border border-line bg-surface p-2 text-2xl" />

        <label className="flex flex-col gap-1 text-sm">
          <span>{WB.math.latex}</span>
          <textarea
            dir="ltr"
            value={latex}
            onChange={(event) => typed(event.target.value)}
            rows={2}
            className="rounded border border-line bg-surface p-2 font-mono text-sm"
          />
          <span className="text-xs text-ink-muted">{WB.math.chemistryHint}</span>
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={display} onChange={(event) => setDisplay(event.target.checked)} />
          {WB.math.display}
        </label>

        <div className="flex min-h-16 items-center justify-center rounded border border-line p-3" style={{ background: "#ffffff", color: "#111111" }}>
          {preview && "src" in preview && <img src={preview.src} alt={latex} className="max-h-40 max-w-full" />}
          {preview && "error" in preview && (
            <p role="alert" className="text-sm text-danger-ink">
              {WB.math.invalid}
            </p>
          )}
        </div>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            {WB.math.cancel}
          </Button>
          <Button onClick={() => onSave({ kind: "math", v: 1, latex: latex.trim(), display })} loading={saving} disabled={!ok}>
            {WB.math.save}
          </Button>
        </div>
      </div>
    </div>
  );
}
