import Link from "next/link";

/**
 * A legal page whose text has not been written yet.
 *
 * These three routes are linked from every page's footer, so they cannot 404.
 * They also cannot carry invented clauses: terms, privacy and refund policies
 * are binding statements about what the business does with money and personal
 * data, and text that reads like a policy but was never approved is worse than
 * an empty page — a user would rely on it.
 *
 * So: a real page, an honest state, noindex, and a link back. Replace the whole
 * component with the approved text when legal supplies it.
 */
export function PolicyPlaceholder({
  title,
  summary,
}: {
  title: string;
  summary: string;
}) {
  return (
    // max-w-7xl to match every other public page's frame, with the reading
    // column held at max-w-3xl inside it. A policy is the one page type that is
    // ALL prose, so widening the container without capping the measure would
    // turn the terms of service into 150-character lines.
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <div className="max-w-3xl">
        <h1 className="mb-4 text-3xl font-extrabold text-ink sm:text-4xl">
          {title}
        </h1>

        <p className="mb-8 text-lg leading-relaxed text-ink-muted">{summary}</p>

        <div className="mb-8 rounded-3xl border border-line bg-primary-soft/60 p-5">
          <p className="font-semibold text-ink">هذه الوثيقة قيد الإعداد</p>
          <p className="mt-1 leading-relaxed text-ink-muted">
            لم تُنشر بعد النسخة المعتمدة من هذا المستند. لا يوجد هنا نص ملزم، ولا
            يُعتدّ بأي صياغة مؤقتة. سيُنشر النص الكامل قبل إتاحة الدفع على المنصة.
          </p>
        </div>

        <Link
          href="/"
          className="text-sm font-semibold text-primary-ink hover:underline"
        >
          العودة إلى الصفحة الرئيسية
        </Link>
      </div>
    </div>
  );
}
