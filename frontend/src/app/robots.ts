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

export default function robots(): MetadataRoute.Robots {
  /*
   * ⚠️ **الفهرسُ لا الشرائح (٢٠٢٦-٠٩-٢٧).** كانَ هذا السطرُ يُعدِّدُ
   * `/sitemap/{id}.xml` بأسمائِها لأنّ Next لا يُنتِجُ فهرساً، و`/sitemap.xml`
   * كانَ ٤٠٤ على الإنتاج. صارَ ذلك العنوانُ يُجيبُ بفهرسٍ (`sitemap-index.xml`
   * خلفَ إعادةِ كتابةٍ في `next.config.ts`) يُشتَقُّ من `generateSitemaps()`
   * نفسِها — فالقائمةُ لا تُكتَبُ بيدٍ هنا ولا هناك، وعنوانٌ واحدٌ هو ما يجرّبُه
   * كلُّ زاحفٍ أوّلاً.
   *
   * ⚠️ **ولا سطرَ `Host:`.** توجيهٌ غيرُ قياسيٍّ كانت Yandex وحدَها تقرؤه وقد
   * تخلّت عنه؛ Google يتجاهلُه، ومدقّقاتُ robots.txt تُعلِّمُه خطأً. والمضيفُ
   * القانونيُّ يقولُه `<link rel="canonical">` على كلِّ صفحة.
   */
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/api/", "/admin", "/dashboard", "/manage/", "/settings", "/login"],
    },
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
