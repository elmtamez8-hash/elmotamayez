/**
 * Whose clock a time is read on.
 *
 * ⚠️ THE READER'S OWN — NOT THE PLATFORM'S (owner decision 2026-09-25). The
 * product has users in Qatar and in Egypt, and Egypt observes daylight saving
 * while Qatar does not, so for half the year a Cairo student and a Doha teacher
 * are an hour apart. Every session, booking and schedule time on every screen is
 * drawn on the viewer's zone: the one stored on their account (`user.timezone`,
 * which the browser stamps on sign-in — the same value the server formats
 * notifications with), else the browser's own.
 *
 * The platform zone (`sessions.timezone`, Asia/Qatar) still decides where a DAY
 * begins for billing, freezes and the gamification week. That is a server
 * question; no screen should draw a time on it.
 */

/** Used only before the browser has been asked (server render, first paint). */
export const PLATFORM_TIME_ZONE = "Asia/Qatar";

export function browserTimeZone(): string | null {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
  } catch {
    return null;
  }
}

/** True when this runtime knows the name — a bad one would throw in every formatter. */
export function isValidTimeZone(zone: string | null | undefined): zone is string {
  if (!zone) return false;

  try {
    new Intl.DateTimeFormat("en", { timeZone: zone });

    return true;
  } catch {
    return false;
  }
}

/**
 * The zone this viewer reads times on: the account's stored zone, else the
 * browser's, else the platform's. `browser` is a parameter so the hook can pass
 * `null` on the server render and a test can pass any zone it likes.
 */
export function resolveViewerTimeZone(
  stored: string | null | undefined,
  browser: string | null | undefined,
): string {
  if (isValidTimeZone(stored)) return stored;
  if (isValidTimeZone(browser)) return browser;

  return PLATFORM_TIME_ZONE;
}

function parts(at: Date, timeZone: string): Record<string, string> {
  const out: Record<string, string> = {};

  for (const part of new Intl.DateTimeFormat("en-US", {
    timeZone,
    hourCycle: "h23",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    weekday: "short",
  }).formatToParts(at)) {
    out[part.type] = part.value;
  }

  return out;
}

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

/** The calendar date `at` falls on in `timeZone`, as `YYYY-MM-DD`. */
export function dateKeyIn(at: Date, timeZone: string): string {
  const p = parts(at, timeZone);

  return `${p.year}-${p.month}-${p.day}`;
}

/** 0 = Sunday, in `timeZone`. */
export function weekdayIn(at: Date, timeZone: string): number {
  return WEEKDAYS.indexOf(parts(at, timeZone).weekday);
}

/** `HH:MM` on a 24-hour clock in `timeZone`. */
export function clockIn(at: Date, timeZone: string): string {
  const p = parts(at, timeZone);

  return `${p.hour}:${p.minute}`;
}

/** How far `timeZone` is ahead of UTC at the instant `at`, in minutes. */
function offsetMinutes(at: Date, timeZone: string): number {
  const p = parts(at, timeZone);
  const asUtc = Date.UTC(
    Number(p.year),
    Number(p.month) - 1,
    Number(p.day),
    Number(p.hour),
    Number(p.minute),
    Number(p.second),
  );

  return Math.round((asUtc - Math.floor(at.getTime() / 1000) * 1000) / 60_000);
}

/**
 * The instant a WALL-CLOCK time names in a given zone: «17:00 on 2026-11-03 in
 * Africa/Cairo» → 15:00Z.
 *
 * ⚠️ THE OFFSET IS THE ONE THAT DATE HAS, not today's. That is the whole reason
 * this exists: a weekly availability window is «Tuesday 17:00 Cairo», and it is
 * 14:00Z in October and 15:00Z in November. Guessing with the offset at the
 * naive instant and correcting once is exact everywhere except inside the hour a
 * clock skips, where it lands on the first valid minute after — the same answer
 * the server's Carbon gives.
 */
export function zonedWallTimeToInstant(dateKey: string, time: string, timeZone: string): Date {
  const [year, month, day] = dateKey.split("-").map(Number);
  const [hour, minute] = time.split(":").map(Number);
  const naive = Date.UTC(year, (month || 1) - 1, day || 1, hour || 0, minute || 0, 0, 0);

  const first = naive - offsetMinutes(new Date(naive), timeZone) * 60_000;
  const second = naive - offsetMinutes(new Date(first), timeZone) * 60_000;

  return new Date(second);
}

/** A `YYYY-MM-DD` key moved by whole days, with no zone involved. */
export function addDaysToKey(dateKey: string, days: number): string {
  const [year, month, day] = dateKey.split("-").map(Number);
  const at = new Date(Date.UTC(year, month - 1, day + days));

  return at.toISOString().slice(0, 10);
}
