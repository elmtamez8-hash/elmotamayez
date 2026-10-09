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
      className="my-8 rounded-3xl border border-line bg-surface-raised p-5 shadow-sm lg:my-0"
    >
      <h2
        id="toc-heading"
        className="mb-4 flex items-center gap-2.5 text-base font-extrabold text-ink"
      >
        <span
          aria-hidden="true"
          className="grid h-9 w-9 place-items-center rounded-xl bg-primary-soft text-primary-ink"
        >
          <ListIcon className="h-5 w-5" />
        </span>
        محتويات المقال
      </h2>

      <ol className="space-y-0.5 text-sm">
        {headings.map((heading) => (
          <li
            key={heading.id}
            // `ms-`، لا `ml-`: المنتَجُ من اليمينِ إلى اليسارِ والخاصّيّةُ
            // الماديّةُ تضعُ الإزاحةَ في الجهةِ الخطأ.
            className={heading.level === 3 ? "ms-4" : ""}
          >
            <a
              href={`#${heading.id}`}
              className="block rounded-xl px-3 py-1.5 font-semibold text-ink-muted transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none"
            >
              {heading.text}
            </a>
          </li>
        ))}
      </ol>
    </nav>
  );
}
