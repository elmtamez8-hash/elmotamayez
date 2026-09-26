import type { Metadata } from "next";
import { LegalDraft } from "@/components/marketplace/LegalDraft";
import { RefundIcon } from "@/components/icons";
import { platformIdentity } from "@/lib/platform";

export const metadata: Metadata = {
  title: "سياسة الاسترجاع",
  // noindex while the text is a draft — see `terms/page.tsx` and `sitemap.ts`.
  robots: { index: false },
};

/**
 * The refund policy, as a DRAFT of what the code does today.
 *
 * ⚠️ THERE IS NO AUTOMATIC REFUND ANYWHERE IN THIS PRODUCT, and the page says so
 * first. Every reversal is a platform officer's button in `/admin` that RECORDS
 * money returned outside the platform — `ManualTransferProvider::supportsRefund()`
 * is false. The one flow a buyer starts themselves is the store's, and it ends
 * in `refund_due` until an officer marks the transfer made.
 *
 * ⚠️ AND NO PROCESSING TIME IS PROMISED, because nothing enforces one.
 */
export default async function RefundsPage() {
  const identity = await platformIdentity();

  return (
    <LegalDraft
      icon={RefundIcon}
      image="/marketplace/banner-refunds.webp"
      title="سياسة الاسترجاع"
      summary="متى يحقّ لك استرداد قيمة حصة أو كورس، وكيف تُقدَّم الطلبات."
      updatedAt="٢٦ سبتمبر ٢٠٢٦"
      platformName={identity.name}
      supportWhatsapp={identity.supportWhatsapp}
      legalName={identity.legalName}
      postalAddress={identity.postalAddress}
      contactEmail={identity.contactEmail}
    >
      <p>
        الدفع على المنصّة حالياً بالتحويل، فالاسترداد كذلك: تراجع إدارة المنصّة كل حالةٍ بنفسها، وتردّ
        المبلغ بتحويلٍ إليك، ثم تسجّله على طلبك. لا يوجد استردادٌ تلقائيّ. الأرقام المذكورة هنا هي
        الإعدادات المعمول بها حالياً.
      </p>

      <h2>كيف تطلب استرداداً</h2>
      <p>
        تواصل مع الدعم واذكر رقم طلبك وسببه. مشتريات المتجر وحدها لها زرُّ طلب استرداد في صفحة
        مشترياتك، بالشروط المذكورة أدناه.
      </p>

      <h2>الحصص التي لا تُخصم أصلاً</h2>
      <p>في هذه الحالات لا يُخصم شيء، أو تعود الحصة إلى رصيدك دون أن تطلب:</p>
      <ul>
        <li>ألغيت الحجز قبل موعد الحصة بـ٢٤ ساعة على الأقل.</li>
        <li>ألغى المدرّس الحصة، أو لم يحضر، أو لم يقدّمها.</li>
        <li>قبل المدرّس عذرك قبل انتهاء الحصة، أو أخرجك منها.</li>
        <li>صُحّح حضورك إلى «معذور» بعد الخصم، فتعود الحصة إلى رصيدك.</li>
      </ul>
      <p>أما الغياب دون عذر، أو الإلغاء المتأخر، أو حضور أقل من نصف الحصة، فتُخصم فيه الحصة.</p>

      <h2>الكورسات</h2>
      <p>
        إذا ردّت المنصّة ثمن كورس، يُلغى تسجيلك فيه ويُغلق محتواه عنك. ولا يُردّ جزءٌ من ثمن كورس: إمّا
        الطلب كلّه وإمّا لا شيء.
      </p>

      <h2>رصيد الحصص</h2>
      <ul>
        <li>يُردّ من الشحنة ما لم تستهلكه منها فقط. الحصص التي حضرتها أو خُصمت منك تبقى مخصومة.</li>
        <li>تُلغى الحجوزات القادمة التي كانت ممولةً من تلك الحصص.</li>
        <li>الرصيد الذي لا يتحرّك لا يسقط؛ نرسل لك تنبيهاً بعد ١٢ شهراً، ويمكنك طلب استرداده من الدعم.</li>
      </ul>

      <h2>الاشتراكات</h2>
      <p>
        إلغاء الاشتراك تتولّاه المنصّة، وهو ردٌّ للمبلغ كاملاً مع إغلاق الوصول، بلا تقسيطٍ على الأيام
        المستعملة. وإن كنت قد اشتريت تجديداً، يبدأ التجديد من يوم الإلغاء. ولا يتجدّد أي اشتراك
        تلقائياً، فلا حاجة لإلغاء شيءٍ إن لم تُرد شهراً آخر.
      </p>

      <h2>المتجر</h2>
      <ul>
        <li>
          يمكنك طلب الاسترداد من صفحة مشترياتك خلال ٤٨ ساعة من الشراء، بشرط ألّا تكون قد فتحت الملف
          الرقمي، وألّا تكون النسخة المطبوعة قد شُحنت. ولكل مشترى طلب استرداد واحد.
        </li>
        <li>إن نفد المخزون بعد دفعك، يصير طلبك مستحقَّ الردّ تلقائياً ونُبلغك بذلك.</li>
        <li>يبقى الطلب «مستحقّ الردّ» حتى تحوّل إليك الإدارةُ المبلغ فعلاً، ثم يُغلق.</li>
      </ul>
    </LegalDraft>
  );
}
