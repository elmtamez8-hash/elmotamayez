/**
 * What a service worker may keep on the device (spec 012 · US2 · SC-005).
 *
 * ⚠️ A PURE FUNCTION, AND THAT IS WHAT MAKES SC-005 MEASURABLE. Written as an
 * `if` inside the worker's `fetch` handler, «zero protected asset is cached» is a
 * claim that could only be checked by driving a real browser with a real service
 * worker — which nobody runs in a development loop, so it would be checked never.
 * Here it is a vitest assertion that finishes in milliseconds.
 *
 * ⚠️ AND IT IS AN ALLOWLIST, NEVER A DENYLIST. A denylist guards `/playback/*`
 * today and opens itself to the first route added tomorrow — no error, no
 * warning, a lesson video sitting in a cache the student can hand to anybody.
 * The default answer here is «no», and a new route stays uncached until somebody
 * writes it down on purpose.
 */

/**
 * The prefixes that are safe to keep: the build's own immutable assets and the
 * two static pages a person may open with no connection.
 *
 * ⚠️ `/_next/static/` IS CONTENT-HASHED, so a stale entry is impossible by
 * construction — the filename changes when the bytes do. `/_next/image` is
 * deliberately absent: it takes a URL as a query parameter, so an allowlist entry
 * for it is an allowlist entry for whatever that parameter names.
 */
const CACHEABLE_PATH_PREFIXES = [
  "/_next/static/",
  "/brand/",
  "/marketplace/",
  "/offline",
] as const;

/** Exact paths, because a prefix would swallow every route beneath them. */
const CACHEABLE_PATHS = ["/manifest.webmanifest", "/favicon.ico", "/icon.svg"] as const;

/**
 * Decide whether one request may be served from, and written to, the cache.
 *
 * @param url the full request URL, as the worker receives it
 * @param origin the page's own origin (`self.location.origin` in the worker)
 */
export function isCacheable(url: string, origin: string): boolean {
  let parsed: URL;

  try {
    parsed = new URL(url, origin);
  } catch {
    // An address we cannot even parse is not one we can reason about.
    return false;
  }

  /*
    ⚠️ CROSS-ORIGIN IS REFUSED BEFORE ANYTHING ELSE, WHICH IS WHAT KEEPS THE VIDEO
    HOST OUT. A lesson is served from the CDN under a signed token that expires;
    cached, the bytes outlive the token and the whole playback design — the grant,
    the renewal loop, the device limit — is worked around by a copy on disk. The
    host is not named here on purpose: naming one vendor would let the next one in.
  */
  if (parsed.origin !== origin) return false;

  const path = parsed.pathname;

  /*
    ⚠️ `/api/*` AND `/playback/*` ARE REFUSED EXPLICITLY EVEN THOUGH THE ALLOWLIST
    ALREADY EXCLUDES THEM. Redundant today and load-bearing the day somebody adds
    a prefix that happens to overlap: these two are the ones where a stale answer
    is a wrong balance on screen, or a video playing after the grant that
    authorised it was revoked.
  */
  if (path.startsWith("/api/") || path.startsWith("/playback/")) return false;

  if ((CACHEABLE_PATHS as readonly string[]).includes(path)) return true;

  return CACHEABLE_PATH_PREFIXES.some((prefix) => path.startsWith(prefix));
}
