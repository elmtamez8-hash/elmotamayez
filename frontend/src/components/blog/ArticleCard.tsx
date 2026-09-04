import Link from "next/link";
import type { ArticleCard as Article } from "@/lib/public-api";
import { formatDate } from "@/lib/labels";
import { ChevronStartIcon, DocumentIcon, TagIcon } from "@/components/icons";

/**
 * بطاقةُ مقالٍ في المدوّنة.
 *
 * ⚠️ **والغلافُ اختياريٌّ، والحالتانِ مقصودتانِ كلتاهما.** `cover_path` عمودٌ
 * قابلٌ للفراغ، وأغلبُ المقالاتِ بلا غلافٍ اليوم — فبطاقةٌ تفترضُ الصورةَ تعرضُ
 * مستطيلاً رماديّاً اثنتَي عشرةَ مرّة. بغلافٍ: صورةٌ ‏١٦:٩ أعلى البطاقة. بلا
 * غلافٍ: الأيقونةُ وحدَها، وهي **زخرفةٌ مُعلَنة** (`aria-hidden`) لا تدّعي
 * تصنيفاً.
 *
 * ⚠️ **وأيقونةٌ واحدةٌ لا خريطةُ تصنيفات**: التصنيفاتُ صفوفٌ حرّةٌ لكلِّ مساحةِ
 * عمل (`cms_categories` بلا قائمةٍ مغلقة)، فخريطةُ slug→أيقونةٍ تُخطئُ أغلبَ
 * الصفوفِ وتُصيبُ ما كتبَه من عرفَ الخريطة. أيقونةٌ ثابتةٌ صادقةٌ في كلِّ حالة.
 *
 * ⚠️ **و`<img>` لا `next/image`**: الغلافُ مسارٌ يكتبُه مدرّسٌ من اللوحة، أي
 * مدخلٌ غيرُ حرفيّ — وملاحظةُ هذا المستودعِ عن تحذيراتِ npm تقولُ صراحةً إنّ
 * ثغرةَ `sharp` تصيرُ حيّةً عندَ أوّلِ مسارٍ غيرِ حرفيٍّ يصلُ `next/image`.
 * `CourseCard` يفعلُ الشيءَ نفسَه للسببِ نفسِه.
 *
 * ⚠️ **والتصنيفُ رابطٌ لا شارةٌ صمّاء**: الواجهةُ الخلفيّةُ تقبلُ `?category=`
 * وتتحقّقُ منه سلفاً، فشارةٌ لا تُنقَرُ هي مرشِّحٌ مبنيٌّ لا يصلُه أحد — «إذنٌ لا
 * يصلُه رابط» في ثوبِ تصفّح.
 *
 * ⚠️ **والرابطُ غيرُ مُرمَّزٍ مسبقاً**: Next يُرمِّزُ `href` بنفسِه، وترميزٌ
 * مزدوجٌ يحوّلُ `%D8%AE` إلى `%25D8%AE` — وكلُّ رابطٍ عربيٍّ ‏٤٠٤. القاعدةُ
 * مكتوبةٌ في الصفحةِ الأصليّةِ وتنتقلُ معها إلى هنا.
 */
export function ArticleCard({
  article,
  featured = false,
}: {
  article: Article;
  /** أوّلُ مقالٍ في الصفحةِ الأولى: أعرضُ وأكبرُ خطّاً. */
  featured?: boolean;
}) {
  return (
    <article className="group relative flex h-full flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised transition duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg active:translate-y-0 active:duration-100">
      {article.cover_url ? (
        <div
          className={`relative overflow-hidden bg-primary-soft ${featured ? "aspect-[21/9]" : "aspect-video"}`}
        >
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={article.cover_url}
            alt=""
            // ⚠️ فارغٌ عمداً: العنوانُ تحتَها يقولُ ما فيها، ووصفٌ ثانٍ ضجيجٌ في
            // قارئِ الشاشةِ لا وصول. وهي زخرفةٌ بجانبِ رابطٍ مسمّىً سلفاً.
            aria-hidden="true"
            loading="lazy"
            className="h-full w-full object-cover transition duration-300 group-hover:scale-105"
          />
        </div>
      ) : null}

      <div className={`flex h-full flex-col p-6 ${featured ? "sm:p-8" : ""}`}>
        <div className="mb-4 flex items-center gap-3">
          {article.cover_url ? null : (
            <span
              className={`flex items-center justify-center rounded-2xl bg-primary-soft text-primary-ink transition duration-200 group-hover:scale-105 ${
                featured ? "h-12 w-12" : "h-10 w-10"
              }`}
              aria-hidden="true"
            >
              <DocumentIcon className={featured ? "h-6 w-6" : "h-5 w-5"} />
            </span>
          )}

          <div className="min-w-0">
            {article.category ? (
              /*
                ⚠️ `relative z-10` — وبدونِه الشارةُ تبدو رابطاً ولا تعملُ أبداً.
                رابطُ العنوانِ يفرشُ `::after` فوقَ البطاقةِ كلِّها ليجعلَها هدفَ
                لمسٍ واحداً، وهذا الطبقُ يقعُ **فوقَ** كلِّ ما سبقَه في نفسِ
                سياقِ التكديس — فنقرةُ التصنيفِ تفتحُ المقال. `CourseCard` يكتبُ
                السطرَ نفسَه على رابطِ مدرّسِه للسببِ نفسِه.
              */
              <Link
                href={`/blog?category=${article.category.slug}`}
                className="relative z-10 text-xs font-semibold text-primary-ink hover:underline"
              >
                {article.category.name}
              </Link>
            ) : (
              <span className="text-xs font-semibold text-ink-muted">مقال</span>
            )}

            <time
              dateTime={article.published_at}
              className="block text-xs text-ink-muted"
            >
              {formatDate(article.published_at)}
            </time>
          </div>
        </div>

        <h2
          className={`font-bold text-ink ${featured ? "text-2xl leading-snug sm:text-3xl" : "text-lg leading-snug"}`}
        >
          {/*
          الرابطُ يغطّي البطاقةَ كلَّها (`after:absolute inset-0`) فتصيرُ المساحةُ
          كلُّها هدفَ لمس — ويبقى الاسمُ المسموعُ عنوانَ المقالِ وحدَه، لا كلَّ ما
          في البطاقة.
        */}
          <Link
            href={`/blog/${article.slug}`}
            /*
              ⚠️ `after:content-['']` صريحاً: الطبقُ الشفّافُ هو ما يجعلُ البطاقةَ
              كلَّها قابلةً للنقر، وعنصرٌ زائفٌ بلا `content` **لا يُصيَّرُ أصلاً**
              — فتصيرُ منطقةُ النقرِ نصَّ العنوانِ وحدَه بلا أثرٍ بصريٍّ يدلُّ على
              ذلك. `CourseCard` يكتبُها صريحةً كذلك.
              ⚠️ وحلقةُ التركيزِ **تبقى**: `focus-visible:outline-none` كانت تحذفُ
              الدليلَ الوحيدَ لمن يتنقّلُ بلوحةِ المفاتيحِ على أينَ هو.
            */
            className="after:absolute after:inset-0 after:rounded-3xl after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary hover:underline"
          >
            {article.title}
          </Link>
        </h2>

        {article.excerpt ? (
          <p
            className={`mt-3 leading-relaxed text-ink-muted ${featured ? "text-base" : "text-sm"}`}
          >
            {article.excerpt}
          </p>
        ) : null}

        {article.tags && article.tags.length > 0 ? (
          <p className="mt-4 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
            <TagIcon className="h-4 w-4" aria-hidden="true" />
            {article.tags.slice(0, 3).map((tag) => (
              <span key={tag.slug}>{tag.name}</span>
            ))}
          </p>
        ) : null}

        {/*
        `mt-auto` فيستوي أسفلُ البطاقاتِ في الشبكةِ مهما اختلفَ طولُ المقتطف.
        و`ChevronStartIcon` لا `ChevronLeft`: الاتّجاهُ يسكنُ اسمَ الأيقونةِ لا
        قلباً في CSS.
      */}
        <span className="mt-auto flex items-center gap-1 pt-5 text-sm font-semibold text-primary-ink">
          اقرأ المقال
          <ChevronStartIcon
            className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1"
            aria-hidden="true"
          />
        </span>
      </div>
    </article>
  );
}
