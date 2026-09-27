/**
 * The one absolute origin of the public site (spec 011 · US5).
 *
 * ⚠️ FIVE THINGS NEED IT AND ALL FIVE MUST AGREE: the sitemap's `<loc>`, the
 * `Sitemap:` line in robots.txt, `<link rel="canonical">`, the `url` inside every
 * JSON-LD block, and the URLs the backend submits to IndexNow. Spelled separately
 * in five files they disagree the first time one of them moves — and a canonical
 * pointing at a host the sitemap does not name is a site telling a search engine
 * two different things about the same page. The backend reads the same value from
 * `FRONTEND_URL`.
 *
 * ⚠️ NOT `NEXT_PUBLIC_*`, deliberately. Everything that reads this is a server
 * component or a route handler, and a `NEXT_PUBLIC_` variable is INLINED AT
 * COMPILE TIME — editing `.env` and restarting in the wrong order ships the old
 * value with no sign of it, which is exactly what spec 020's socket host cost.
 * `NEXT_PUBLIC_APP_URL` below is read as a FALLBACK only, from server code, where
 * it is an ordinary environment variable.
 *
 * ⛔ **AND IT MUST EXIST AT BUILD TIME TOO, WHICH IS WHERE `localhost` LEAKED.**
 * Pages Next prerenders during `next build` — /terms, /refunds, the 404 — bake
 * their `<link rel="canonical">` and `og:image` into the HTML then, and the
 * container's runtime `environment:` does not exist inside `docker build`. So the
 * first response after every deploy (until revalidation) told crawlers the page
 * lived at `http://localhost:3000`. The image now receives `SITE_URL` as a build
 * argument (`docker/frontend.Dockerfile`), and this falls back to
 * `NEXT_PUBLIC_APP_URL` — the same `${APP_URL}`, already a build argument for the
 * socket host — before it ever reaches `localhost`, which remains only for a
 * developer's machine and a CI build with neither set.
 */
export const SITE_URL = resolveSiteUrl(process.env);

/** Exported for its test: the order of the three answers is the whole fix. */
export function resolveSiteUrl(env: Record<string, string | undefined>): string {
  // `||`, not `??`: compose writes an unset `${APP_URL}` as an EMPTY string.
  const configured = env.SITE_URL?.trim() || env.NEXT_PUBLIC_APP_URL?.trim() || "";

  if (configured === "" && env.NODE_ENV === "production") {
    // A warning, not a throw: CI builds for production with neither variable on
    // purpose, and a build that failed there would prove nothing about a deploy.
    console.warn(
      "SITE_URL and NEXT_PUBLIC_APP_URL are both unset; canonical URLs will name localhost.",
    );
  }

  return (configured || "http://localhost:3000").replace(/\/+$/, "");
}

/** An absolute URL for a site-relative path. */
export function siteUrl(path: string): string {
  return `${SITE_URL}${path.startsWith("/") ? path : `/${path}`}`;
}
