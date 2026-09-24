import { api } from "@/lib/api";

/**
 * فتحُ لوحةِ `‎/admin` من واجهةٍ مُصادَقٍ عليها بمفتاحٍ آخَر.
 *
 * ⛔ **رابطٌ مباشرٌ إلى `‎/admin` يهبطُ على شاشةِ دخولٍ ثانية، وهذا ليس عطلاً بل
 * بنيةً**: الواجهةُ تحملُ رمزَ Sanctum في `localStorage`، واللوحةُ جلسةُ Laravel
 * بكوكي. و`localStorage` **لا يسافرُ مع طلبِ صفحة** — هو مخزنُ جافاسكربت لا
 * ترويسة — فالطلبُ يصلُ بلا إثباتٍ إطلاقاً. ووثيقةُ `UserResource` تقولُها عندَ
 * `may_access_admin_panel`: «هذا الحقلُ لا يفتحُ شيئاً».
 *
 * فالخطوةُ ليست رابطاً بل نداءً: نطلبُ تذكرةً **بالمفتاحِ الذي نملكُه**، ثمّ
 * نذهبُ إلى العنوانِ الذي يردُّه الخادم.
 *
 * ⚠️ **والعنوانُ يُستعمَلُ كما جاءَ ولا يُبنى هنا.** اسمُ المسارِ والتذكرةُ
 * قرارُ الخادم، وتركيبُ العنوانِ في المتصفّحِ تهجئةٌ ثانيةٌ تنكسرُ يومَ يتغيّرُ
 * المسارُ في ملفِّ المسارات.
 *
 * ⚠️ **و`assign` لا `replace`**: الرجوعُ إلى الصفحةِ التي جئتَ منها سلوكٌ
 * معقول، والتذكرةُ مصروفةٌ على أيِّ حالٍ فلا شيءَ يُعادُ استعمالُه بالزرِّ
 * الخلفيّ.
 */
export async function openAdminPanel(to?: string): Promise<void> {
  // `to` is a path inside the panel, named HERE — on the authenticated POST —
  // and never on the link that spends the ticket. The server drops anything
  // that is not under `/admin`, so a stray value lands on the panel's root.
  const { url } = await api.post<{ url: string }>("/auth/panel-ticket", to === undefined ? {} : { to });

  window.location.assign(url);
}
