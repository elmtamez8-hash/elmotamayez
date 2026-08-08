"use client";

import { Badge } from "@/components/ui/Badge";
import type { ContentStatus } from "@/lib/courses";

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

  if (status === "published") return <Badge tone="success">منشور</Badge>;
  if (status === "archived") return <Badge tone="neutral">مؤرشف</Badge>;

  return <Badge tone="neutral">مسودّة</Badge>;
}
