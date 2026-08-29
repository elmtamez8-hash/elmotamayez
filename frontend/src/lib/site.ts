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
 */
export const SITE_URL = (
  process.env.SITE_URL ?? "http://localhost:3000"
).replace(/\/+$/, "");

/** An absolute URL for a site-relative path. */
export function siteUrl(path: string): string {
  return `${SITE_URL}${path.startsWith("/") ? path : `/${path}`}`;
}
