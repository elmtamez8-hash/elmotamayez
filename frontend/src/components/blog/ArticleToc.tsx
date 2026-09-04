import type { ArticleHeading } from "@/lib/article";
import { ListIcon } from "@/components/icons";

/**
 * فهرسُ المقال.
 *
 * ⚠️ **لا يُعرَضُ لعنوانٍ واحد.** فهرسٌ ببندٍ واحدٍ ليس فهرساً؛ هو صندوقٌ يزيدُ
 * المسافةَ بينَ القارئِ وأوّلِ سطر.
 *
 * ⚠️ **والمِرساةُ تأتي من الدالّةِ التي حقنَتها في النصّ** ({@link
 * import("@/lib/article").withHeadingAnchors})، لا من اشتقاقٍ ثانٍ هنا: هجاءانِ
 * للخوارزميّةِ نفسِها يفترقانِ عندَ أوّلِ عنوانٍ مكرَّرٍ فيشيرُ الفهرسُ إلى مِرساةٍ
 * لا وجودَ لها — بلا خطأٍ في أيِّ مكان.
 *
 * ⚠️ **والفهرسُ `<nav>` باسمٍ مسموع**: قارئُ الشاشةِ يقفزُ بينَ المعالم، وقائمةُ
 * روابطَ بلا معلَمٍ تُقرَأُ روابطَ سائبةً في وسطِ المقال.
 */
export function ArticleToc({ headings }: { headings: ArticleHeading[] }) {
  if (headings.length < 2) return null;

  return (
    <nav
      aria-labelledby="toc-heading"
      className="my-8 rounded-3xl border border-line bg-surface p-5"
    >
      <h2
        id="toc-heading"
        className="mb-3 flex items-center gap-2 text-sm font-bold text-ink"
      >
        <ListIcon className="h-4 w-4 text-primary-ink" aria-hidden="true" />
        محتويات المقال
      </h2>

      <ol className="space-y-1.5 text-sm">
        {headings.map((heading) => (
          <li
            key={heading.id}
            // `ms-`، لا `ml-`: المنتَجُ من اليمينِ إلى اليسارِ والخاصّيّةُ
            // الماديّةُ تضعُ الإزاحةَ في الجهةِ الخطأ.
            className={heading.level === 3 ? "ms-4" : ""}
          >
            <a
              href={`#${heading.id}`}
              className="text-ink-muted underline-offset-4 transition hover:text-primary-ink hover:underline"
            >
              {heading.text}
            </a>
          </li>
        ))}
      </ol>
    </nav>
  );
}
