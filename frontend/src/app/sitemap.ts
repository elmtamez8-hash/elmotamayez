import type { MetadataRoute } from "next";
import { publicApi } from "@/lib/public-api";
import { SITE_URL } from "@/lib/site";

/**
 * The sitemap (011 · US5 · FR-036 · SC-011).
 *
 * ⚠️ IT READS THE PUBLIC BLOG FEED, NOT A QUERY OF ITS OWN. «١٠٠٪ من المنشور
 * وصفرٌ من غيره» is exactly `publiclyListed()` on the API side — status, the
 * publication date, the soft-delete scope and the workspace's participation, all
 * four — and a second predicate spelled here drifts into two answers that each
 * look right in isolation: the blog showing an article the map omits, or the map
 * advertising a draft and sending a crawler to a 404.
 *
 * ⚠️ AND `generateSitemaps()` IS THE SPLIT FR-036 ASKS FOR. Next then serves
 * `/sitemap/0.xml`, `/sitemap/1.xml`, … A single file is fine at today's volume
 * and stops being fine silently: search engines cap a sitemap at 50,000 URLs and
 * 50 MB, and the failure is «the tail of the site is not indexed», which nothing
 * reports.
 */

/** Matches the API's own cap. One page per sitemap file. */
const PER_PAGE = 200;

/** The pages that exist whatever is in the database. Chunk 0 carries them. */
const STATIC_PATHS = [
  "/",
  "/teachers",
  "/courses",
  "/blog",
  "/pricing",
  "/about",
  "/terms",
  "/privacy",
  "/refunds",
];

export async function generateSitemaps(): Promise<{ id: number }[]> {
  try {
    // One cheap request for the count. The alternative — walking every page to
    // find out how many there are — costs the whole feed twice.
    const { meta } = await publicApi.articles({ per_page: "1" });
    const chunks = Math.max(1, Math.ceil(meta.total / PER_PAGE));

    return Array.from({ length: chunks }, (_, id) => ({ id }));
  } catch {
    /*
     * ⚠️ ONE CHUNK, NEVER ZERO. An API blip during a build would otherwise
     * produce a sitemap index with no children — and a search engine reading it
     * concludes the site has no pages, which is a far more expensive answer than
     * a sitemap that is briefly missing its articles. Chunk 0 always carries the
     * static pages.
     */
    return [{ id: 0 }];
  }
}

export default async function sitemap({
  id,
}: {
  id: number;
}): Promise<MetadataRoute.Sitemap> {
  const staticEntries: MetadataRoute.Sitemap =
    id === 0
      ? STATIC_PATHS.map((path) => ({
          url: `${SITE_URL}${path}`,
          changeFrequency: "weekly",
          priority: path === "/" ? 1 : 0.7,
        }))
      : [];

  let articles: MetadataRoute.Sitemap = [];

  try {
    const { data } = await publicApi.articles({
      page: String(id + 1),
      per_page: String(PER_PAGE),
    });

    articles = data.map((article) => ({
      // Encoded because the slugs are Arabic. A raw one is not a legal `<loc>`,
      // and the map would be rejected as malformed rather than partially read.
      url: `${SITE_URL}/blog/${encodeURIComponent(article.slug)}`,
      // `updated_at`, not `published_at`: this field is what tells a crawler
      // whether to come back, and an article corrected last night has not been
      // republished.
      lastModified: new Date(article.updated_at),
      changeFrequency: "monthly",
      priority: 0.6,
    }));
  } catch {
    // The static half still ships. A sitemap missing its articles for one build
    // is recoverable; a build that fails because the API was slow is not.
  }

  return [...staticEntries, ...articles];
}
