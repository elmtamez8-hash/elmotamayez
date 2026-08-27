/**
 * Session times, always in the zone the server declared.
 *
 * Never `toLocaleString("ar")` without a timeZone: that renders in whatever zone
 * the machine is set to, so the same session shows a different hour to a student
 * whose laptop is still on last holiday's timezone. FR-004 and FR-054 make one
 * declared zone the rule, and the API sends it alongside every time for exactly
 * this call.
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
