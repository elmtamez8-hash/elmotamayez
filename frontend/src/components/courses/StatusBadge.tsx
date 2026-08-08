"use client";

import { Badge } from "@/components/ui/Badge";
import type { ContentStatus } from "@/lib/courses";
import { statusLabel, statusTone } from "@/lib/labels";

/**
 * A node's state, and the reason it is hidden when its own state is not it.
 *
 * "Published" on a lesson inside a draft section answers a question the teacher
 * did not ask, and sends them looking for a fault in the lesson. The fourth
 * state here is derived rather than stored: the node is published, and its
 * section or chapter is not.
 */
export function StatusBadge({
  status,
  blockedBy = null,
}: {
  status: ContentStatus;
  blockedBy?: "section" | "chapter" | null;
}) {
  if (status === "published" && blockedBy !== null) {
    return (
      <Badge tone="warning">
        {blockedBy === "section" ? "محجوب بقسمه" : "محجوب بفصله"}
      </Badge>
    );
  }

  // Through labels.ts, which already held these exact three strings — a second
  // copy here is a second place for "مسودّة" to become "مسوّدة".
  return <Badge tone={statusTone(status)}>{statusLabel(status)}</Badge>;
}
