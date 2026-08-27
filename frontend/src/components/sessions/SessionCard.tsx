import Link from "next/link";

import { ClockIcon } from "@/components/icons";
import { Badge } from "@/components/ui/Badge";
import { SeatBadge } from "@/components/sessions/SeatBadge";
import type { ClassSession } from "@/lib/class-sessions";
import { formatSessionClock, formatSessionTime } from "@/lib/session-format";

/**
 * One session in a list.
 *
 * The title links into the session — the row is not a dead card. Every screen
 * this phase adds is reachable from the one before it, and a card that shows a
 * session without offering a way into it is how a whole player shipped
 * unreachable in spec 004.
 *
 * ⚠️ THE WHOLE CARD IS THE TARGET, and it is one `<Link>` stretched over the
 * article rather than a click handler on it. A 40px title inside a 140px card is
 * a thumb-sized miss on a phone, and an `onClick` on a `<div>` is a control with
 * no href — no middle-click, no «open in new tab», nothing in the status bar,
 * and no keyboard focus unless somebody remembers `tabIndex`. `isolate` on the
 * article keeps the overlay under the badges so they stay readable, and the
 * `action` slot sits in its own stacking context so a button inside a card still
 * receives its own clicks.
 */
export function SessionCard({
  session,
  href,
  action,
  time = "full",
}: {
  session: ClassSession;
  href: string;
  action?: React.ReactNode;
  /**
   * `clock` drops the day, for a list whose headings already state it. A date
   * repeated on every card under «اليوم» is noise the reader has to skip past
   * on the way to the hour, which is the only part that differs.
   */
  time?: "full" | "clock";
}) {
  return (
    <article
      className="group relative isolate rounded-3xl border border-line bg-surface-raised p-5 transition-colors duration-200 hover:border-primary/40 focus-within:border-primary/40"
    >
      <div className="mb-3 flex items-start justify-between gap-3">
        <h3 className="font-semibold text-ink">
          <Link
            href={href}
            // Stretched: the pseudo-element covers the article, so the accessible
            // name stays the title while the target is the whole card.
            className="rounded before:absolute before:inset-0 before:content-[''] group-hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {session.title}
          </Link>
        </h3>
        <Badge tone={session.status === "cancelled" ? "danger" : "info"}>
          {session.status_label}
        </Badge>
      </div>

      <p className="mb-3 flex items-center gap-1.5 text-sm text-ink-muted">
        {/* `className` REPLACES the icon's default size rather than adding to
            it, so the h-4 w-4 has to be repeated here. Colour is inherited. */}
        <ClockIcon className="h-4 w-4 shrink-0" />
        <span>
          {time === "clock"
            ? formatSessionClock(session.starts_at, session.timezone)
            : formatSessionTime(session.starts_at, session.timezone)}{" "}
          · <bdi>{session.duration_minutes}</bdi> دقيقة
        </span>
      </p>

      {/* Above the stretched link, so the action inside it is still pressable. */}
      <div className="relative z-10 flex flex-wrap items-center gap-2">
        <Badge tone="neutral">{session.type_label}</Badge>
        <SeatBadge seats={session.seats} />
        {action}
      </div>
    </article>
  );
}
