import Link from "next/link";

import { Badge } from "@/components/ui/Badge";
import { SeatBadge } from "@/components/sessions/SeatBadge";
import type { ClassSession } from "@/lib/class-sessions";
import { formatSessionTime } from "@/lib/session-format";

/**
 * One session in a list.
 *
 * The title links into the session — the row is not a dead card. Every screen
 * this phase adds is reachable from the one before it, and a card that shows a
 * session without offering a way into it is how a whole player shipped
 * unreachable in spec 004.
 */
export function SessionCard({
  session,
  href,
  action,
}: {
  session: ClassSession;
  href: string;
  action?: React.ReactNode;
}) {
  return (
    <article className="rounded-xl border border-line bg-surface-raised p-5">
      <div className="mb-3 flex items-start justify-between gap-3">
        <h3 className="font-semibold text-ink">
          <Link
            href={href}
            className="rounded hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {session.title}
          </Link>
        </h3>
        <Badge tone={session.status === "cancelled" ? "danger" : "info"}>
          {session.status_label}
        </Badge>
      </div>

      <p className="mb-3 text-sm text-ink-muted">
        {formatSessionTime(session.starts_at, session.timezone)} ·{" "}
        <bdi>{session.duration_minutes}</bdi> دقيقة
      </p>

      <div className="flex flex-wrap items-center gap-2">
        <Badge tone="neutral">{session.type_label}</Badge>
        <SeatBadge seats={session.seats} />
        {action}
      </div>
    </article>
  );
}
