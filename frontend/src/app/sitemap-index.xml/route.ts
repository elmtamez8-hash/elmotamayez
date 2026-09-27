import { generateSitemaps } from "@/app/sitemap";
import { SITE_URL } from "@/lib/site";

/**
 * The sitemap INDEX, served at `/sitemap.xml` through a rewrite in
 * `next.config.ts` (2026-09-27, the pre-launch audit).
 *
 * ⚠️ NEXT PRODUCES NO INDEX. `generateSitemaps()` in `app/sitemap.ts` serves
 * `/sitemap/0.xml`, `/sitemap/1.xml`, … and nothing at `/sitemap.xml` — measured
 * on production: 404. That is the one address every crawler and every
 * webmaster tool tries first, whatever robots.txt says. This answers it with a
 * `<sitemapindex>` naming each chunk.
 *
 * ⚠️ THE CHUNK LIST IS `generateSitemaps()` ITSELF, never a copy: the count grows
 * with the articles, and an index written by hand would hide the tail of the
 * site — the defect the chunking exists to prevent.
 *
 * ⚠️ AND IT LIVES AT `/sitemap-index.xml` BEHIND A REWRITE rather than at
 * `app/sitemap.xml/route.ts`, because `app/sitemap.ts` is a metadata route that
 * owns the `sitemap` name; two files claiming one path take the WHOLE app down
 * (see `docs/gotchas/frontend.md`), and a rewrite cannot.
 *
 * Rendered per request, for the reason `sitemap.ts` and `robots.ts` are: it reads
 * the API and `SITE_URL`, neither of which exists inside `docker build`.
 */
export const dynamic = "force-dynamic";

const escapeXml = (value: string): string =>
  value.replace(/[<>&'"]/g, (char) => `&#${char.charCodeAt(0)};`);

export async function GET(): Promise<Response> {
  const chunks = await generateSitemaps();
  const entries = chunks
    .map(({ id }) => `  <sitemap><loc>${escapeXml(`${SITE_URL}/sitemap/${id}.xml`)}</loc></sitemap>`)
    .join("\n");

  return new Response(
    `<?xml version="1.0" encoding="UTF-8"?>\n<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${entries}\n</sitemapindex>\n`,
    { headers: { "Content-Type": "application/xml; charset=utf-8" } },
  );
}
