"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { WB } from "@/lib/whiteboard/strings";

/** The current page as a PNG or an SVG file (US1). The work is the caller's. */
export function ExportMenu({ onExport }: { onExport: (kind: "png" | "svg") => Promise<void> }) {
  const [busy, setBusy] = useState<"png" | "svg" | null>(null);
  const [failed, setFailed] = useState(false);

  const run = async (kind: "png" | "svg") => {
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
    <div className="flex items-center gap-1">
      <Button size="sm" variant="ghost" loading={busy === "png"} loadingLabel={WB.exporting} onClick={() => run("png")}>
        {WB.exportPng}
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
