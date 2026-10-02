"use client";

import { WB } from "@/lib/whiteboard/strings";

/** Where an import the teacher started stands, as the panel shows it. */
export type ImportView =
  | { phase: "idle" }
  | { phase: "uploading" }
  | { phase: "queued"; position: number }
  | { phase: "converting" }
  | { phase: "done"; pages: number }
  | { phase: "failed"; message: string };

/**
 * «استيراد» (story 4): a PDF or a picture, added as pages after the page shown.
 * Pure — the board owns the upload and the polling, so closing this menu never
 * stops an import, and the teacher keeps drawing on other pages meanwhile.
 */
export function ImportPanel({ view, onFile }: { view: ImportView; onFile: (file: File) => void }) {
  const busy = view.phase === "uploading" || view.phase === "queued" || view.phase === "converting";

  return (
    <div className="flex flex-col gap-1.5 border-t border-line pt-1.5 text-sm">
      <label className="flex flex-col gap-1">
        <span className="font-medium">{WB.importing.choose}</span>
        <input
          type="file"
          accept="application/pdf,.pdf,image/png,image/jpeg"
          disabled={busy}
          onChange={(event) => {
            const file = event.target.files?.[0];
            event.target.value = ""; // the same file can be chosen again
            if (file) onFile(file);
          }}
          className="text-xs"
        />
      </label>
      <p className="text-xs text-ink-muted">{WB.importing.hint}</p>
      {view.phase !== "idle" && view.phase !== "failed" && (
        <p role="status" className="text-xs">
          {view.phase === "uploading" && WB.importing.uploading}
          {view.phase === "queued" && WB.importing.queued(view.position)}
          {view.phase === "converting" && WB.importing.converting}
          {view.phase === "done" && WB.importing.done(view.pages)}
        </p>
      )}
      {view.phase === "failed" && (
        <p role="alert" className="text-xs text-danger-ink">
          {view.message}
        </p>
      )}
    </div>
  );
}
