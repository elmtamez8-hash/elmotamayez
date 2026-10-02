"use client";

import type { SaveState } from "@/lib/whiteboard/autosave";
import { WB } from "@/lib/whiteboard/strings";

const TONE: Record<SaveState, string> = {
  saved: "text-ink-muted",
  saving: "text-ink-muted",
  offline: "font-medium text-ink",
  failed: "text-danger-ink",
  unprotected: "font-medium text-ink",
};

/** Where the teacher's work is, in one line (US2). Announced politely — never a popup mid-lesson. */
export function SaveIndicator({ state }: { state: SaveState }) {
  return (
    <span role="status" aria-live="polite" className={`text-xs ${TONE[state]}`} data-save-state={state}>
      {WB.saveState[state]}
    </span>
  );
}
