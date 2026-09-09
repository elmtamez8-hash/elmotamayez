import Link from "next/link";

import { ClockIcon } from "@/components/icons";
import { Badge } from "@/components/ui/Badge";
import { SeatBadge } from "@/components/sessions/SeatBadge";
import { SessionOwners } from "@/components/sessions/SessionOwners";
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
  variant = "listing",
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
  /**
   * WHICH QUESTION THE CARD IS ANSWERING — and it decides two things at once,
   * deliberately, because they are two halves of the same distinction.
   *
   * `listing` is a card the reader might still act on: the course tab and the
   * teacher's calendar, where «are there seats left?» is a live question. It is
   * the default so that neither of those surfaces changed when this prop landed.
   *
   * `timetable` is the reader's OWN booked hour. Two consequences follow.
   * ⚠️ `SeatBadge` comes off: every row is a seat they already hold, so a full
   * individual lesson rendered «اكتملت المقاعد» in a danger tone against their
   * own booking — an alarm about the thing going right. ⚠️ And the course and
   * the teacher go on: a student studies with several teachers at once, and the
   * title alone («حصة اليوم — مراجعةٌ سريعة») names no subject and nobody. On a
   * course page the course name would be the third mention on one screen, which
   * is why this is a variant rather than a field rendered whenever it arrives.
   */
  variant?: "listing" | "timetable";
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

      {/* الكورسُ ومَن يُدرّسه — انظر {@see SessionOwners}: الحقلانِ كانا يصلانِ
          من الخادمِ ولا يُرسمانِ في أيِّ مكان. */}
      {variant === "timetable" && <SessionOwners session={session} className="mb-2" />}

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
        {variant === "listing" && <SeatBadge seats={session.seats} />}
        {action}
      </div>
    </article>
  );
}
