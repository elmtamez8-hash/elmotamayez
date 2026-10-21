import { SiteHeader } from "@/components/marketplace/SiteHeader";
import { SiteFooter } from "@/components/marketplace/SiteFooter";

/**
 * الطرفانِ الوحيدانِ في التطبيقِ بلا ترويسةٍ ولا تذييل.
 *
 * ⚠️ صفحتانِ يصلُهما القارئُ من خارجِ المنتَجِ — رابطٌ في شهادةٍ مطبوعةٍ ودعوةٌ في
 * بريد — وكانتا **طريقاً مسدوداً**: لا شعارَ ولا تنقّلَ ولا سبيلَ إلى أيِّ شيءٍ
 * آخرَ إلّا شريطُ العنوان. والموقَّعُ فيهما لم يكنْ يرى صورتَه ولا قائمةَ حسابِه،
 * وهو نصفُ بلاغِ ٢٠٢٦-٠٩-٠٦: «عايزه يظهر في كامل الصفحات».
 *
 * ⚠️ وترويسةُ الموقعِ لا ترويسةُ اللوحة: كلتا الصفحتَينِ تُفتَحُ بلا حسابٍ في
 * الحالةِ الغالبة — من يتحقّقُ من شهادةٍ ليسَ طالباً عندَنا — و`SiteHeader` هي
 * التي تعرفُ حالتَي «ضيف» و«موقَّع» معاً.
 *
 * No `<main>` here: every state of the page renders `AuthShell`, which carries
 * `<main id="main">` itself — a second one here nested two landmarks with one
 * id (assistant journey 2026-10-21).
 */
export default function OutsideChromeLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <SiteHeader />
      {children}
      <SiteFooter />
    </>
  );
}
