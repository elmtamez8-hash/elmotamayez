import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/site";

/**
 * robots.txt (011 · US5).
 *
 * ⚠️ THE DISALLOW LIST IS NOT A SECURITY CONTROL AND MUST NOT BE READ AS ONE.
 * Every path below is already refused to an unauthenticated request by the API;
 * this only keeps a crawler from spending its budget on login walls and from
 * putting `/dashboard` in a result page. Anything that would actually matter if
 * it were fetched is guarded by `auth:sanctum` and a policy, not by this file —
 * a robots.txt is a public list of the paths you would rather nobody visited.
 *
 * `/admin` is Filament's session-authenticated panel, and `/api` answers JSON
 * that has no business in a search result.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/api/", "/admin", "/dashboard", "/manage/", "/settings", "/login"],
    },
    // The sitemap INDEX, which is what `generateSitemaps()` produces. Naming
    // `/sitemap/0.xml` here would advertise the first chunk and hide the rest.
    sitemap: `${SITE_URL}/sitemap.xml`,
    host: SITE_URL,
  };
}
