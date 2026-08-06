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
