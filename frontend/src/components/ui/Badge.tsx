import type { ReactNode } from "react";
import { statusLabel, statusTone, TONE_CLASSES, type StatusTone } from "@/lib/labels";

/**
 * A small pill. The label is always inside it, so colour is emphasis rather than
 * the carrier of meaning — which is what keeps it readable for anyone who cannot
 * separate the tones.
 */
export function Badge({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: StatusTone;
}) {
  return (
    <span
      className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ${TONE_CLASSES[tone]}`}
    >
      {children}
    </span>
  );
}

/** The common case: an API status string, translated and toned in one step. */
export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={statusTone(status)}>{statusLabel(status)}</Badge>;
}
