import type { Metadata } from "next";

import { platformName } from "@/lib/platform";
import { siteUrl } from "@/lib/site";

/**
 * The metadata of a fixed public page: title, description, an absolute
 * canonical and the Open Graph card that points at the same address.
 *
 * ⚠️ ONE HELPER, BECAUSE NEXT DOES NOT DEEP-MERGE `openGraph`. A page that sets
 * `openGraph.url` alone REPLACES the root layout's object and silently drops its
 * `siteName`, `locale`, `type` and default image — the share card turns into a
 * grey strip in every chat app. So the whole object is built here, once, and the
 * five listing and policy pages (/teachers, /courses, /pricing, /about, /privacy)
 * ask for it instead of each writing half of it.
 *
 * ⚠️ AND THE CANONICAL IGNORES THE QUERY STRING ON PURPOSE. `/teachers?subject=…`
 * is the same page filtered; naming the bare path consolidates every filter and
 * page number onto the one address the sitemap lists.
 */
export async function publicPageMetadata({
  path,
  title,
  description,
  image,
}: {
  path: string;
  title: string;
  description: string;
  /** Site-relative; the page's own banner, so the card matches the page. */
  image: string;
}): Promise<Metadata> {
  const name = await platformName();
  const url = siteUrl(path);

  return {
    title,
    description,
    alternates: { canonical: url },
    openGraph: {
      title: `${title} | ${name}`,
      description,
      url,
      siteName: name,
      type: "website",
      locale: "ar_QA",
      images: [{ url: siteUrl(image) }],
    },
  };
}
