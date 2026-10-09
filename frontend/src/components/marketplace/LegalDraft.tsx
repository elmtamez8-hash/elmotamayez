import Link from "next/link";
import type { ComponentType, ReactNode } from "react";
import {
  AlertIcon,
  ChevronStartIcon,
  DocumentIcon,
  HomeIcon,
  MessagesIcon,
  ShieldIcon,
  SiteIcon,
  WhatsAppIcon,
} from "@/components/icons";
import { arabicDigits } from "@/lib/numerals";
import { LegalArticle, LegalShell, type LegalPage } from "./LegalShell";

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
  current,
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
  current: LegalPage;
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
  const contacts: { Icon: ComponentType<{ className?: string }>; label: string; value: ReactNode }[] = [
    { Icon: SiteIcon, label: "المنصّة", value: <bdi>{platformName}</bdi> },
    ...(legalName !== "" ? [{ Icon: DocumentIcon, label: "الجهة المسؤولة", value: <bdi>{legalName}</bdi> }] : []),
    ...(postalAddress !== "" ? [{ Icon: HomeIcon, label: "العنوان", value: <bdi>{postalAddress}</bdi> }] : []),
    ...(contactEmail !== "" ? [{ Icon: MessagesIcon, label: "البريد الإلكتروني", value: <bdi dir="ltr">{contactEmail}</bdi> }] : []),
    ...(supportWhatsapp !== ""
      ? [{ Icon: WhatsAppIcon, label: "واتساب الدعم", value: <bdi dir="ltr">{arabicDigits(`+${supportWhatsapp}`)}</bdi> }]
      : []),
  ];

  return (
    <LegalShell current={current} icon={icon} image={image} title={title} summary={summary}>
      <div role="note" className="flex gap-4 rounded-3xl border border-accent/40 bg-accent/10 p-6">
        <span
          aria-hidden="true"
          className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-accent text-accent-foreground"
        >
          <AlertIcon className="h-5 w-5" />
        </span>
        <div>
          <p className="mb-1 font-extrabold text-ink">مسودة — قيد المراجعة القانونية</p>
          <p className="leading-relaxed text-ink-muted">
            هذا النص يصف القواعد التي تعمل بها المنصّة اليوم فعلاً، لكنه لم يُعتمد
            قانونياً بعد، وقد تتغيّر صياغته قبل اعتماده.
          </p>
        </div>
      </div>

      <LegalArticle>{children}</LegalArticle>

      <section
        aria-labelledby="legal-contact"
        className="rounded-3xl border border-line bg-surface-raised p-6 shadow-sm sm:p-8"
      >
        <h2 id="legal-contact" className="mb-5 text-xl font-extrabold text-ink">
          للتواصل
        </h2>
        <ul className="grid gap-4 sm:grid-cols-2">
          {contacts.map(({ Icon, label, value }) => (
            <li key={label} className="flex items-center gap-3">
              <span
                aria-hidden="true"
                className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink"
              >
                <Icon className="h-5 w-5" />
              </span>
              <span className="min-w-0">
                <span className="block text-xs text-ink-muted">{label}</span>
                <span className="block break-words font-semibold text-ink">{value}</span>
              </span>
            </li>
          ))}
        </ul>
        <p className="mt-6 flex items-center gap-2 border-t border-line pt-5 text-sm text-ink-muted">
          <ShieldIcon className="h-4 w-4 shrink-0 text-primary-ink" />
          <span>
            أو من صفحة{" "}
            <Link href="/privacy" className="font-semibold text-primary-ink underline underline-offset-4">
              سياسة الخصوصية
            </Link>{" "}
            للطلبات الخاصة ببياناتك.
          </span>
        </p>
      </section>

      <div className="flex flex-wrap items-center justify-between gap-4">
        <p className="text-xs text-ink-muted">آخر تحديث: {updatedAt}</p>
        <Link
          href="/"
          className="link-underline inline-flex items-center gap-1.5 text-sm font-semibold text-primary-ink transition-all duration-200 ease-out hover:gap-2.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        >
          <ChevronStartIcon />
          العودة إلى الصفحة الرئيسية
        </Link>
      </div>
    </LegalShell>
  );
}
