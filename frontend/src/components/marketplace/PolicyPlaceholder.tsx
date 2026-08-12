import Link from "next/link";
import type { ComponentType } from "react";
import { ChevronStartIcon } from "@/components/icons";
import { PageBanner } from "@/components/ui/PageBanner";

/**
 * A legal page whose text has not been written yet.
 *
 * These three routes are linked from every page's footer, so they cannot 404.
 * They also cannot carry invented clauses: terms, privacy and refund policies
 * are binding statements about what the business does with money and personal
 * data, and text that reads like a policy but was never approved is worse than
 * an empty page — a user would rely on it.
 *
 * So: a real page, an honest state, noindex, and a link back. Replace the
 * body when legal supplies the approved text; the banner stays.
 *
 * ⚠️ The banner is the SAME component the four section pages use, and that is
 * the reason it is here. A legal page that looks like a different product is
 * exactly where a reader stops and wonders whether they are still on the site
 * they trusted — and these are the three pages a cautious visitor opens.
 */
export function PolicyPlaceholder({
  title,
  summary,
  icon,
  image,
  tone = "primary",
}: {
  title: string;
  summary: string;
  icon: ComponentType<{ className?: string }>;
  image: string;
  tone?: "primary" | "secondary" | "accent" | "ink";
}) {
  return (
    // max-w-7xl to match every other public page's frame, with the reading
    // column held at max-w-3xl inside it. A policy is the one page type that is
    // ALL prose, so widening the container without capping the measure would
    // turn the terms of service into 150-character lines.
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <PageBanner
        icon={icon}
        image={image}
        title={title}
        description={summary}
        tone={tone}
      />

      <div className="max-w-3xl">
        <div className="rounded-3xl border border-line bg-primary-soft/60 p-6">
          <p className="mb-1 font-bold text-ink">هذه الوثيقة قيد الإعداد</p>
          <p className="leading-relaxed text-ink-muted">
            لم تُنشر بعد النسخة المعتمدة من هذا المستند. لا يوجد هنا نص ملزم، ولا
            يُعتدّ بأي صياغة مؤقتة. سيُنشر النص الكامل قبل إتاحة الدفع على المنصة.
          </p>
        </div>

        {/* `hover:gap-2.5` moves the chevron, not the label — the arrow leads
            and the words stay where they were read. `link-underline` grows its
            rule from the inline start, so it runs right-to-left in Arabic with
            no second rule and no flip. */}
        <Link
          href="/"
          className="link-underline mt-8 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-ink transition-all duration-200 ease-out hover:gap-2.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        >
          <ChevronStartIcon />
          العودة إلى الصفحة الرئيسية
        </Link>
      </div>
    </div>
  );
}
