"use client";

import { Button } from "@/components/ui/Button";
import { WB } from "@/lib/whiteboard/strings";

/**
 * Who edits this board right now (R-09). Shown only to a tab that does NOT hold
 * the lock, or to the holder being asked to hand it over.
 */
export function LockBanner({
  heldBy,
  canTake,
  taking,
  handoverRequested,
  onTake,
}: {
  heldBy: string | null;
  canTake: boolean;
  taking: boolean;
  handoverRequested: boolean;
  onTake: () => void;
}) {
  if (handoverRequested) {
    return (
      <p role="alert" className="text-xs font-medium text-ink">
        {WB.handoverNotice}
      </p>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-2 text-xs">
      <span className="font-medium text-ink">{WB.readOnly}</span>
      {heldBy && <span className="text-ink-muted">{WB.editingNow(heldBy)}</span>}
      {canTake &&
        (taking ? (
          <span className="text-ink-muted" aria-live="polite">
            {WB.takingEditing}
          </span>
        ) : (
          <Button size="sm" variant="secondary" onClick={onTake}>
            {WB.takeEditing}
          </Button>
        ))}
    </div>
  );
}
