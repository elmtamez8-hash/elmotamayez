"use client";

import { Alert } from "@/components/ui/Alert";
import type { JoinTicket } from "@/lib/class-sessions";

/**
 * Where the video will be.
 *
 * Today it is a status panel. Tomorrow it is the wrapper around a provider's
 * SDK — and the whole point is that only THIS file changes when that day comes.
 * A broadcast SDK imposes its shape on everything it touches (a context, a set
 * of hooks, a lifecycle), so pulling one in before the commercial choice is made
 * means tearing it out of every place it reached.
 *
 * The room around it is real regardless: the door, the ticket, the heartbeat,
 * the host controls and the whole register work without a single frame of video,
 * because attendance never passes through the provider (research §R3 · R15).
 */
export function BroadcastStage({ ticket }: { ticket: JoinTicket }) {
  return (
    <div className="space-y-4">
      <div
        className="flex aspect-video w-full items-center justify-center rounded-2xl border border-line bg-surface"
        role="region"
        aria-label="مسرح البثّ"
      >
        <p className="text-sm text-ink-muted">
          {ticket.role === "host" ? "أنت مضيف هذه الغرفة." : "أنت مشارك في هذه الغرفة."}
        </p>
      </div>

      <Alert tone="info" title="البثّ المباشر قيد التجهيز">
        غرفة الحصة تعمل والحضور يُحتسب، والبثّ بالصوت والصورة يُفعَّل فور اعتماد مزوّد البثّ.
      </Alert>
    </div>
  );
}
