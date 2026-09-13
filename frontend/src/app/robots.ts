import type { MetadataRoute } from "next";
import { generateSitemaps } from "@/app/sitemap";
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
/*
 * ⚠️ **مُصيَّرٌ وقتَ الطلب، وهذا هو ما يجعلُ `SITE_URL` يصلُ أصلاً.** المتغيّرُ
 * يُقرأُ وقتَ التشغيلِ من بيئةِ الحاوية — ولا وجودَ له داخلَ `docker build` —
 * فنسخةٌ مُصيَّرةٌ وقتَ البناءِ تحملُ سقوطَ `lib/site.ts` إلى
 * `http://localhost:3000` وتُخدَمُ هكذا للأبد. قِيسَ على الإنتاجِ بعدَ ضبطِ
 * المتغيّرِ: صفحاتُ المقالاتِ (ISR) صحّحت رابطَها القانونيَّ فوراً بينما بقيَ
 * `robots.txt` يقولُ `Host: http://localhost:3000` — وهو الفرقُ نفسُه بينَ ما
 * يُصيَّرُ وقتَ الطلبِ وما يُخبَزُ في الصورة.
 */
export const dynamic = "force-dynamic";

export default async function robots(): Promise<MetadataRoute.Robots> {
  /*
   * ⚠️ **الشرائحُ بأسمائِها، لأنّ Next لا يُنتِجُ فهرساً على الإطلاق.** كانَ هذا
   * السطرُ `${SITE_URL}/sitemap.xml` وفوقَه تعليقٌ يقولُ إنّه «الفهرسُ الذي
   * يُنتِجُه `generateSitemaps()`» — وهو غيرُ صحيح: التوثيقُ يقولُ إنّ الملفّاتِ
   * تُخدَمُ على `/sitemap/[id].xml` ولا شيءَ يُخدَمُ على `/sitemap.xml`. قِيسَ
   * على الإنتاج ٢٠٢٦-٠٩-١٣: `/sitemap.xml` ‏٤٠٤ و`/sitemap/0.xml` ‏٢٠٠ — أي أنّ
   * `robots.txt` كانَ يدلُّ كلَّ زاحفٍ على عنوانٍ غيرِ موجود، والموقعُ كلُّه بلا
   * خريطةٍ يقرؤها أحد.
   *
   * ⚠️ والقائمةُ تُشتَقُّ من `generateSitemaps()` نفسِها لا تُكتَبُ بيدٍ هنا:
   * عددُ الشرائحِ يكبرُ مع المقالات، وقائمةٌ ثابتةٌ تُخفي ذيلَ الموقعِ في صمتٍ —
   * وهو بالضبطِ العطبُ الذي وُجِدَ التقسيمُ لتفاديه.
   */
  const chunks = await generateSitemaps();

  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/api/", "/admin", "/dashboard", "/manage/", "/settings", "/login"],
    },
    sitemap: chunks.map(({ id }) => `${SITE_URL}/sitemap/${id}.xml`),
    host: SITE_URL,
  };
}
