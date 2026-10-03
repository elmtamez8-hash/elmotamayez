"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import type { ExportKind } from "@/lib/whiteboard/excalidraw-api";
import { WB } from "@/lib/whiteboard/strings";

/** The current page as a PNG, a JPG or an SVG file (US1). The work is the caller's. */
export function ExportMenu({ onExport }: { onExport: (kind: ExportKind) => Promise<void> }) {
  const [busy, setBusy] = useState<ExportKind | null>(null);
  const [failed, setFailed] = useState(false);

  const run = async (kind: ExportKind) => {
    setBusy(kind);
    setFailed(false);
    try {
      await onExport(kind);
    } catch {
      setFailed(true);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="flex items-center gap-1" role="group" aria-label={WB.export}>
      <span className="text-xs text-ink-muted" aria-hidden="true">
        {WB.export}
      </span>
      <Button size="sm" variant="ghost" loading={busy === "png"} loadingLabel={WB.exporting} onClick={() => run("png")}>
        {WB.exportPng}
      </Button>
      <Button size="sm" variant="ghost" loading={busy === "jpg"} loadingLabel={WB.exporting} onClick={() => run("jpg")}>
        {WB.exportJpg}
      </Button>
      <Button size="sm" variant="ghost" loading={busy === "svg"} loadingLabel={WB.exporting} onClick={() => run("svg")}>
        {WB.exportSvg}
      </Button>
      {failed && (
        <p role="alert" className="text-xs text-danger-ink">
          {WB.exportFailed}
        </p>
      )}
    </div>
  );
}
