import { timezoneLabel } from "@/lib/labels";

/**
 * Session times, always in an EXPLICIT zone — and that zone is the VIEWER's.
 *
 * Never `toLocaleString("ar")` without a timeZone: that renders in whatever zone
 * the machine is set to, silently.
 *
 * ⚠️ WHICH ZONE CHANGED ON 2026-09-25 (owner decision). Until then every caller
 * passed the platform's declared zone (`session.timezone`, Asia/Qatar), which was
 * right for a product in Qatar alone. It has users in Egypt too, and Egypt
 * observes DST: a Cairo student who picked 17:00 on the request form (drawn on
 * the browser's clock) saw 18:00 in «حصصي» (drawn on Doha's) from 2026-10-29.
 * Callers now pass `useViewerTimeZone()` — the account's stored zone, else the
 * browser's — and these functions stay pure so a test can name any zone.
 */
export function formatSessionTime(iso: string, timeZone: string): string {
  return new Date(iso).toLocaleString("ar", {
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
    timeZone,
    numberingSystem: "latn",
  });
}

/**
 * The same, with the zone NAMED: «الثلاثاء ٣ نوفمبر ١٧:٠٠ (توقيت مصر)».
 *
 * For the screens where two people in two countries read one time — a private
 * request and its answer, a reschedule — so neither has to guess which clock a
 * bare «17:00» is on.
 */
export function formatSessionTimeWithZone(iso: string, timeZone: string): string {
  return `${formatSessionTime(iso, timeZone)} (${timezoneLabel(timeZone)})`;
}

/**
 * The reader's clock AND the other party's, when the two differ at that instant
 * (owner decision 2026-09-26): «الثلاثاء ٣ نوفمبر ١٧:٠٠ بتوقيتك · ١٨:٠٠ بتوقيت
 * المدرّس». For a private request and a reschedule, which two people in two
 * countries negotiate over.
 *
 * ⚠️ «DIFFER» IS THE HOUR AT THAT INSTANT, NOT THE ZONE NAME. Cairo and Doha are
 * both UTC+3 until 2026-10-29 — two names, one clock — and then this prints
 * the reader's time with its zone named, as `formatSessionTimeWithZone` does.
 * The other party's date is added only when it is a different day there.
 */
export function formatSessionTimeBoth(
  iso: string,
  timeZone: string,
  otherTimeZone: string | null | undefined,
  otherRole: string,
): string {
  if (!otherTimeZone || otherTimeZone === timeZone) return formatSessionTimeWithZone(iso, timeZone);

  const mine = `${sessionDayKey(iso, timeZone)} ${formatSessionClock(iso, timeZone)}`;
  const theirs = `${sessionDayKey(iso, otherTimeZone)} ${formatSessionClock(iso, otherTimeZone)}`;

  if (mine === theirs) return formatSessionTimeWithZone(iso, timeZone);

  const other =
    sessionDayKey(iso, otherTimeZone) === sessionDayKey(iso, timeZone)
      ? formatSessionClock(iso, otherTimeZone)
      : formatSessionTime(iso, otherTimeZone);

  return `${formatSessionTime(iso, timeZone)} بتوقيتك · ${other} بتوقيت ${otherRole}`;
}

/**
 * One meeting slot of a group — «السبت 17:00» — on the given clock. The server
 * sends the next meeting in the slot as an instant, so the weekday comes from
 * that date in THIS zone (01:00 Doha is the previous evening in Cairo).
 */
export function formatCohortSlot(iso: string, timeZone: string): string {
  const weekday = new Date(iso).toLocaleDateString("ar", { weekday: "long", timeZone });

  return `${weekday} ${formatSessionClock(iso, timeZone)}`;
}

/** Just the clock part — for a row that already shows the day. */
export function formatSessionClock(iso: string, timeZone: string): string {
  return new Date(iso).toLocaleTimeString("ar", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone,
    numberingSystem: "latn",
  });
}

/**
 * The day a session falls on, in the server's zone, as `YYYY-MM-DD`.
 *
 * ⚠️ NOT `new Date(iso).toDateString()` AND NOT A SLICE OF THE ISO STRING. The
 * first reads the machine's zone, so a lesson at 01:00 Doha groups under the
 * previous day for a student whose laptop is still on last holiday's timezone —
 * the same defect `formatSessionTime` exists to prevent, moved one level up into
 * which HEADING the card lands under. The second ignores the offset entirely.
 * `en-CA` is the shortest locale that formats as `YYYY-MM-DD`, which sorts.
 */
export function sessionDayKey(iso: string, timeZone: string): string {
  return new Date(iso).toLocaleDateString("en-CA", { timeZone });
}

/**
 * What to call that day: «اليوم», «غداً», or the weekday and date.
 *
 * `now` is a parameter rather than a `new Date()` inside, so a test can state
 * what today is instead of arranging for one.
 */
export function formatSessionDay(iso: string, timeZone: string, now: Date = new Date()): string {
  const day = sessionDayKey(iso, timeZone);
  const today = sessionDayKey(now.toISOString(), timeZone);

  if (day === today) return "اليوم";

  const tomorrow = new Date(now.getTime() + 86_400_000);

  if (day === sessionDayKey(tomorrow.toISOString(), timeZone)) return "غداً";

  return new Date(iso).toLocaleDateString("ar", {
    weekday: "long",
    day: "numeric",
    month: "long",
    timeZone,
    numberingSystem: "latn",
  });
}
