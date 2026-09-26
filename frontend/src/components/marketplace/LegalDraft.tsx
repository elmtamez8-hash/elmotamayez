import Link from "next/link";
import type { ComponentType, ReactNode } from "react";
import { ChevronStartIcon } from "@/components/icons";
import { PageBanner } from "@/components/ui/PageBanner";

/**
 * A legal page whose text is written but not yet approved.
 *
 * Replaces `PolicyPlaceholder`, which said «لا يوجد هنا نص ملزم … سيُنشر النص
 * الكامل قبل إتاحة الدفع» — true when it shipped, false once transfers opened.
 * The terms and the refund policy now describe what the code actually enforces,
 * so a reader deciding whether to pay can see the rules; but no lawyer has
 * signed them yet, and the banner says exactly that.
 *
 * ⚠️ THE DRAFT BANNER IS NOT DECORATION AND IS NOT OPTIONAL. A page that reads
 * like binding terms and was never reviewed is the thing `PolicyPlaceholder`
 * existed to prevent; the banner is what keeps this page on the right side of
 * that line until legal review ends. Removing it is the approval, not a style
 * change.
 *
 * ⚠️ AND THE CONTACT LINES COME FROM THE PLATFORM SETTINGS, NEVER FROM THIS
 * FILE. An empty setting drops its line rather than printing a label with
 * nothing after it.
 */
export function LegalDraft({
  title,
  summary,
  icon,
  image,
  updatedAt,
  platformName,
  supportWhatsapp,
  legalName = "",
  postalAddress = "",
  contactEmail = "",
  children,
}: {
  title: string;
  summary: string;
  icon: ComponentType<{ className?: string }>;
  image: string;
  /** Already written in Arabic, e.g. «٢٦ سبتمبر ٢٠٢٦». */
  updatedAt: string;
  platformName: string;
  supportWhatsapp: string;
  legalName?: string;
  postalAddress?: string;
  contactEmail?: string;
  children: ReactNode;
}) {
  return (
    // The same frame as every public page, with the reading column capped: a
    // policy is all prose, and full-width lines of it are unreadable.
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <PageBanner icon={icon} image={image} title={title} description={summary} />

      <div className="max-w-3xl space-y-6">
        <div role="note" className="rounded-3xl border border-line bg-primary-soft/60 p-6">
          <p className="mb-1 font-bold text-ink">مسودة — قيد المراجعة القانونية</p>
          <p className="leading-relaxed text-ink-muted">
            هذا النص يصف القواعد التي تعمل بها المنصّة اليوم فعلاً، لكنه لم يُعتمد
            قانونياً بعد، وقد تتغيّر صياغته قبل اعتماده.
          </p>
        </div>

        <article className="prose-policy text-ink">{children}</article>

        <section aria-labelledby="legal-contact" className="rounded-2xl border border-line p-5">
          <h2 id="legal-contact" className="font-semibold text-ink">
            للتواصل
          </h2>
          <ul className="mt-2 space-y-1 text-sm text-ink-muted">
            <li>
              المنصّة: <bdi>{platformName}</bdi>
            </li>
            {legalName !== "" && (
              <li>
                الجهة المسؤولة: <bdi>{legalName}</bdi>
              </li>
            )}
            {postalAddress !== "" && (
              <li>
                العنوان: <bdi>{postalAddress}</bdi>
              </li>
            )}
            {contactEmail !== "" && (
              <li>
                البريد الإلكتروني: <bdi dir="ltr">{contactEmail}</bdi>
              </li>
            )}
            {supportWhatsapp !== "" && (
              <li>
                واتساب الدعم: <bdi dir="ltr">+{supportWhatsapp}</bdi>
              </li>
            )}
            <li>
              أو من صفحة <Link href="/privacy" className="text-primary-ink underline">سياسة الخصوصية</Link> للطلبات
              الخاصة ببياناتك.
            </li>
          </ul>
        </section>

        <p className="text-xs text-ink-muted">آخر تحديث: {updatedAt}</p>

        <Link
          href="/"
          className="link-underline inline-flex items-center gap-1.5 text-sm font-semibold text-primary-ink transition-all duration-200 ease-out hover:gap-2.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        >
          <ChevronStartIcon />
          العودة إلى الصفحة الرئيسية
        </Link>
      </div>
    </div>
  );
}
