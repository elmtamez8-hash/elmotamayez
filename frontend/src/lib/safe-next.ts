/**
 * Where to send someone after they sign in or sign up (spec 027 · FR-005).
 *
 * The course page is public and is opened from a search engine, so most people
 * who press «اشترك» have no account at all. Sending them to their dashboard to
 * start again is the complication this feature exists to remove — the choice
 * they made travels in `?next=` and brings them back to the same screen.
 *
 * ⚠️ AND `next` IS ATTACKER-SUPPLIED, ON THE SIGN-IN FLOW OF ALL PLACES. An open
 * redirect here is credential phishing wearing the platform's own domain: the
 * victim follows a link to the real login page, signs in for real, and lands on
 * somebody else's page believing they are still here.
 *
 * ⚠️ «STARTS WITH `/` AND NOT WITH `//`» IS ONE CHARACTER SHORT. Browsers
 * normalise a backslash to a forward slash while parsing a URL, so `/\evil.example`
 * and `/\/evil.example` are protocol-relative and land off-origin. The origin is
 * compared after parsing rather than pattern-matched, so nothing rests on having
 * enumerated the prefixes.
 *
 * ⚠️ AND THE GUARD LIVES AT THE REDIRECT, NOT ON EACH PAGE THAT CARRIES THE
 * PARAMETER. The value crosses login, register and the signup wizard; one
 * spelling per screen is how the fourth screen ships without it.
 */
export function safeNext(next: string | null | undefined, fallback: string): string {
  if (!next || !next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\")) {
    return fallback;
  }

  try {
    // `window` is absent while a page is prerendered on the server; a relative
    // path is already same-origin by construction, so the string stands.
    const origin = typeof window === "undefined" ? null : window.location.origin;

    if (origin === null) {
      return next;
    }

    const url = new URL(next, origin);

    return url.origin === origin ? `${url.pathname}${url.search}` : fallback;
  } catch {
    return fallback;
  }
}
