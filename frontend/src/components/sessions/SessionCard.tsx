"use client";

import Link from "next/link";

import { ClockIcon } from "@/components/icons";
import { Badge } from "@/components/ui/Badge";
import { SeatBadge } from "@/components/sessions/SeatBadge";
import { SessionOwners } from "@/components/sessions/SessionOwners";
import type { ClassSession } from "@/lib/class-sessions";
import { formatSessionClock } from "@/lib/session-format";
import { counted, NOUNS } from "@/lib/labels";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

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
  const zone = useViewerTimeZone();
  const cancelled = session.status === "cancelled";
  const part = (options: Intl.DateTimeFormatOptions) =>
    new Date(session.starts_at).toLocaleDateString("ar", {
      ...options,
      timeZone: zone,
      numberingSystem: "latn",
    });

  return (
    <article className="group relative isolate flex gap-4 rounded-3xl border border-line bg-surface-raised p-4 shadow-sm transition duration-200 hover:border-primary/40 hover:shadow-md hover:shadow-primary/10 focus-within:border-primary/40 sm:p-5">
      {/* The day as a calendar leaf (owner request 2026-10-11), in the brand
          band's colour — the date is what a reader scans a list of sessions by.
          Under a day heading (`clock`) the leaf carries the hour instead. A
          cancelled session's leaf goes pale so it reads as off the calendar. */}
      <div
        className={`flex w-[4.5rem] shrink-0 flex-col items-center justify-center self-start rounded-2xl px-2 py-3 text-center ${
          cancelled
            ? "bg-primary-soft text-primary-ink"
            : "bg-squares bg-primary text-white"
        }`}
      >
        {time === "clock" ? (
          <span className="text-lg font-extrabold leading-none tabular-nums">
            {formatSessionClock(session.starts_at, zone)}
          </span>
        ) : (
          <>
            <span className="text-xs font-semibold opacity-85">
              {part({ weekday: "long" })}
            </span>
            <span className="my-1 text-3xl font-extrabold leading-none tabular-nums">
              {part({ day: "numeric" })}
            </span>
            <span className="text-xs font-semibold opacity-85">
              {part({ month: "long" })}
            </span>
          </>
        )}
      </div>

      <div className="min-w-0 flex-1">
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
          <Badge tone={cancelled ? "danger" : "info"}>
            {session.status_label}
          </Badge>
        </div>

        {/* الكورسُ ومَن يُدرّسه — انظر {@see SessionOwners}: الحقلانِ كانا يصلانِ
          من الخادمِ ولا يُرسمانِ في أيِّ مكان. */}
        {variant === "timetable" && (
          <SessionOwners session={session} className="mb-2" />
        )}

        <p className="mb-3 flex items-center gap-1.5 text-sm text-ink-muted">
          {/* `className` REPLACES the icon's default size rather than adding to
            it, so the h-4 w-4 has to be repeated here. Colour is inherited. */}
          <ClockIcon className="h-4 w-4 shrink-0" />
          {/* The leaf shows the day, so the line shows the hour — unless the leaf
            already shows the hour, and then the line is the length alone. */}
          <span>
            {time === "clock"
              ? ""
              : `${formatSessionClock(session.starts_at, zone)} · `}
            {counted(session.duration_minutes, NOUNS.minutes)}
          </span>
        </p>

        {/* Above the stretched link, so the action inside it is still pressable. */}
        <div className="relative z-10 flex flex-wrap items-center gap-2">
          <Badge tone="neutral">{session.type_label}</Badge>
          {variant === "listing" && <SeatBadge seats={session.seats} />}
          {action}
        </div>
      </div>
    </article>
  );
}
