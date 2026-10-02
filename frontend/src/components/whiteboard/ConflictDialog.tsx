"use client";

import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { WB } from "@/lib/whiteboard/strings";

/**
 * A page saved somewhere else since this tab loaded it (FR-012). The server never
 * picks a winner and neither does this code: the teacher does. «إلغاء» leaves the
 * page paused with their copy still on screen and on the device.
 */
export function ConflictDialog({
  open,
  onTakeServer,
  onKeepMine,
  onCancel,
}: {
  open: boolean;
  onTakeServer: () => void;
  /** Story 3 (adding a page) wires it; until then the choice is not offered. */
  onKeepMine?: () => void;
  onCancel: () => void;
}) {
  return (
    <Modal
      open={open}
      title={WB.conflictTitle}
      message={WB.conflictMessage}
      confirmLabel={WB.takeServerCopy}
      onConfirm={onTakeServer}
      onCancel={onCancel}
    >
      {/* `undefined`, not `false`: Modal draws its inset panel for any child at all. */}
      {onKeepMine ? (
        <Button variant="secondary" onClick={onKeepMine}>
          {WB.keepMineAsNewPage}
        </Button>
      ) : undefined}
    </Modal>
  );
}
