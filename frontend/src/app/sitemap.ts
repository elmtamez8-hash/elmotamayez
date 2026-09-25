import type { MetadataRoute } from "next";
import { publicApi, type Paginated } from "@/lib/public-api";
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

/*
 * ⚠️ **مُصيَّرٌ وقتَ التشغيل، لا وقتَ البناء.** الخريطةُ تُبنى من الـAPI ومن
 * `SITE_URL`، وكلاهما غيرُ موجودٍ داخلَ `docker build` — فنسخةُ البناءِ خريطةٌ
 * فارغةٌ بعناوينِ `localhost`، وتُخدَمُ إلى الأبدِ بلا شيءٍ يُعيدُ توليدَها.
 *
 * ⚠️ **و`revalidate = 60` وحدَه لم يمنعْ ذلك** — بعدَ النشرِ كانت الخريطةُ
 * تُخدَمُ بعناوينِ `localhost`، لأنّ Next يُصيِّرُ المسارَ ساكناً وقتَ البناءِ ثمّ
 * يُجدِّدُه في الخلفية، فالنسخةُ الأولى هي نسخةُ البناء. `force-dynamic` يجعلُ
 * كلَّ طلبٍ يُبنى من الـAPI و`SITE_URL` الحقيقيَّين، والكلفةُ طلباتٌ قليلةٌ من
 * زاحفٍ يمرُّ بضعَ مرّاتٍ في اليوم.
 */
export const dynamic = "force-dynamic";

/**
 * The marketplace's cap (`config/marketplace.php` · `max_per_page`). ⚠️ NOT the
 * articles' 200: the teachers and courses requests VALIDATE `per_page`, so 200
 * answers 422, the `catch` swallows it, and the map silently ships with no
 * teacher and no course in it.
 */
const MARKETPLACE_PER_PAGE = 48;

/**
 * A ceiling on the walk, so a runaway `last_page` cannot turn one crawler visit
 * into hundreds of API calls. 40 × 48 is ~1,900 of each — far past today, and the
 * day it is reached the answer is chunking these like the articles, not a
 * bigger number here.
 */
const MAX_MARKETPLACE_PAGES = 40;

/**
 * Every page of one public marketplace feed. It reads the SAME feed the
 * `/teachers` and `/courses` listings read, for the reason the articles do: the
 * API's `publiclyListed` predicate is the one answer to «what is public», and a
 * second one spelled here would drift from it.
 */
async function walk<T>(
  fetchPage: (params: Record<string, string>) => Promise<Paginated<T>>,
): Promise<T[]> {
  const rows: T[] = [];

  for (let page = 1; page <= MAX_MARKETPLACE_PAGES; page++) {
    const { data, meta } = await fetchPage({
      page: String(page),
      per_page: String(MARKETPLACE_PER_PAGE),
    });

    rows.push(...data);

    if (page >= meta.last_page) break;
  }

  return rows;
}

/**
 * Teachers and courses, each in its own `try`: one feed failing must not take
 * the other down with it, or the static half beside them.
 */
async function marketplaceEntries(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = [];

  try {
    const teachers = await walk(publicApi.teachers);

    entries.push(
      ...teachers.map((teacher) => ({
        // The same `slug ?? uuid` the cards link to — a uuid address 308s to
        // the slug, and a map that lists a redirect is a map a crawler distrusts.
        url: `${SITE_URL}/teachers/${encodeURIComponent(teacher.slug ?? teacher.uuid)}`,
        changeFrequency: "weekly" as const,
        priority: 0.8,
      })),
    );
  } catch {
    // The rest of the map still ships.
  }

  try {
    const courses = await walk(publicApi.courses);

    entries.push(
      ...courses.map((course) => ({
        url: `${SITE_URL}/courses/${encodeURIComponent(course.slug ?? course.uuid)}`,
        changeFrequency: "weekly" as const,
        priority: 0.7,
      })),
    );
  } catch {
    // The rest of the map still ships.
  }

  return entries;
}

/**
 * ⚠️ **`id` ليسَ رقماً، والتوقيعُ الخطأُ أفرغَ الخريطةَ بالكامل.** توثيقُ Next
 * يُصرِّحُ به `Promise<string>`؛ الملفُّ كانَ يُصرِّحُه `number` ويقارنُ
 * `id === 0` — وهي كاذبةٌ دائماً، فسقطَ **النصفُ الثابتُ** (`/` · `/teachers` ·
 * `/courses` · `/blog` …) وهو نصفٌ لا يعتمدُ على شبكةٍ ولا على قاعدةِ بيانات.
 * ثمّ `String(id + 1)` يُنتِجُ صفحةً غيرَ صالحةٍ فيبتلعُها `catch` ويسقطُ النصفُ
 * الآخر. النتيجةُ `<urlset>` فارغٌ تماماً — قِيسَ على الإنتاجِ وعلى المُطوِّرِ
 * معاً في ٢٠٢٦-٠٩-١٣، أي أنّه ليسَ عطبَ بناءٍ بل عطبُ توقيع.
 *
 * `Number(await …)` صحيحٌ للثلاثةِ: وعدٌ، أو نصّ، أو رقمٌ — فلا يتعلّقُ بما
 * تُقرِّرُه نسخةُ Next القادمة.
 */
export default async function sitemap(props: {
  id: Promise<string>;
}): Promise<MetadataRoute.Sitemap> {
  const id = Number(await props.id);
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

  // Teachers and courses ride in chunk 0 with the static pages: the article
  // count decides how many chunks there are, and a later chunk repeating them
  // would list every profile once per chunk.
  const marketplace = id === 0 ? await marketplaceEntries() : [];

  return [...staticEntries, ...marketplace, ...articles];
}
