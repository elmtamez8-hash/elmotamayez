import type { Metadata } from "next";
import { LegalDraft } from "@/components/marketplace/LegalDraft";
import { RefundIcon } from "@/components/icons";
import {
  attendanceBar,
  cancellationWindow,
  dormancyPeriod,
  storeRefundSentence,
} from "@/lib/legal-terms";
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
 *
 * ⚠️ AND NO OPERATIONAL NUMBER IS WRITTEN HERE — the same rule as `/terms`:
 * each is a `platform_settings` row read live from `GET /api/v1/platform`, and
 * a number the API did not send drops its sentence.
 */
export default async function RefundsPage() {
  const identity = await platformIdentity();
  const { terms } = identity;
  const freeWindow = cancellationWindow(terms.cancellationWindowMinutes);
  const bar = attendanceBar(terms.attendanceRequiredStayPercent);
  const dormancy = dormancyPeriod(terms.dormantNoticeMonths);
  const storeRefund = storeRefundSentence(terms.storeRefundWindowHours);

  return (
    <LegalDraft
      icon={RefundIcon}
      image="/marketplace/banner-refunds.webp"
      title="سياسة الاسترجاع"
      summary="متى يحقّ لك استرداد قيمة حصة أو كورس، وكيف تُقدَّم الطلبات."
      updatedAt="٢٧ سبتمبر ٢٠٢٦"
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
        {freeWindow && <li>{`ألغيت الحجز قبل موعد الحصة بما لا يقلّ عن ${freeWindow}.`}</li>}
        <li>ألغى المدرّس الحصة، أو لم يحضر، أو لم يقدّمها.</li>
        <li>قبل المدرّس عذرك قبل انتهاء الحصة، أو أخرجك منها.</li>
        <li>صُحّح حضورك إلى «معذور» بعد الخصم، فتعود الحصة إلى رصيدك.</li>
      </ul>
      <p>
        أما الغياب دون عذر، أو الإلغاء المتأخر{bar && `، أو حضور أقل من ${bar}`}، فتُخصم فيه
        الحصة.
      </p>

      <h2>الكورسات</h2>
      <p>
        إذا ردّت المنصّة ثمن كورس، يُلغى تسجيلك فيه ويُغلق محتواه عنك. ولا يُردّ جزءٌ من ثمن كورس: إمّا
        الطلب كلّه وإمّا لا شيء.
      </p>

      <h2>رصيد الحصص</h2>
      <ul>
        <li>يُردّ من الشحنة ما لم تستهلكه منها فقط. الحصص التي حضرتها أو خُصمت منك تبقى مخصومة.</li>
        <li>تُلغى الحجوزات القادمة التي كانت ممولةً من تلك الحصص.</li>
        <li>
          الرصيد الذي لا يتحرّك لا يسقط؛
          {dormancy ? ` نرسل لك تنبيهاً بعد ${dormancy}، ` : " "}
          وإن أردت استرداده فتواصل مع الدعم.
        </li>
      </ul>

      <h2>الاشتراكات</h2>
      {/* The owner's rule of 2026-09-27 — the same words as `/terms`. */}
      <ul>
        <li>إلغاء الاشتراك تتولّاه المنصّة، ويُغلق الوصول عند الإلغاء.</li>
        <li>
          يُردّ لك الجزء غير المستعمل من الاشتراك فقط: في اشتراك المدّة بنسبة الأيام المتبقّية منه،
          وفي اشتراك عدد الحصص بعدد الحصص التي لم تُقدَّم لك بعد.
        </li>
        <li>الحصص التي قُدِّمت لك لا يُردّ ثمنها.</li>
        <li>إن كنت قد اشتريت تجديداً، يبدأ التجديد من يوم الإلغاء.</li>
        <li>لا يتجدّد أي اشتراك تلقائياً، فلا حاجة لإلغاء شيءٍ إن لم تُرد مدّةً أخرى.</li>
      </ul>

      <h2>المتجر</h2>
      <ul>
        {storeRefund && <li>{storeRefund}</li>}
        <li>إن نفد المخزون بعد دفعك، يصير طلبك مستحقَّ الردّ تلقائياً ونُبلغك بذلك.</li>
        <li>يبقى الطلب «مستحقّ الردّ» حتى تحوّل إليك الإدارةُ المبلغ فعلاً، ثم يُغلق.</li>
      </ul>
    </LegalDraft>
  );
}
