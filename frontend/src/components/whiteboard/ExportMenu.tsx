"use client";

import { useState } from "react";

import { MenuChip, MenuRow } from "@/components/whiteboard/MenuParts";
import type { ExportKind } from "@/lib/whiteboard/excalidraw-api";
import { WB } from "@/lib/whiteboard/strings";

const KINDS: { kind: ExportKind; label: string }[] = [
  { kind: "png", label: WB.exportPng },
  { kind: "jpg", label: WB.exportJpg },
  { kind: "svg", label: WB.exportSvg },
];

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
    <div role="group" aria-label={WB.export}>
      <MenuRow label={WB.export}>
        {KINDS.map(({ kind, label }) => (
          <MenuChip key={kind} loading={busy === kind} loadingLabel={WB.exporting} onClick={() => run(kind)}>
            {label}
          </MenuChip>
        ))}
        {failed && (
          <p role="alert" className="w-full text-xs text-danger-ink">
            {WB.exportFailed}
          </p>
        )}
      </MenuRow>
    </div>
  );
}
