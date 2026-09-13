import Link from "next/link";
import type { ArticleTopic } from "@/lib/public-api";
import { arabicNumber } from "@/lib/numerals";
import { BookIcon, TagIcon } from "@/components/icons";

/**
 * شريطُ التصفّحِ في فهرسِ المدوّنة.
 *
 * ⚠️ **وُجِدَ لأنّ المرشِّحَ كانَ مبنيّاً بلا بابٍ يصلُه.** `/blog` يقبلُ
 * `?category=` و`?tag=` ويُصفّي بهما منذُ ٠١١، والطريقُ الوحيدُ إليهما كانَ
 * بطاقةً تحملُ ذلكَ التصنيفَ بالصدفةِ في الاثنَي عشرَ المعروضة — «إذنٌ لا يصلُه
 * رابط» في ثوبِ تصفّح، وهي العائلةُ التي دفعَ هذا المستودعُ ثمنَها في
 * `settlement.requestRate` و`writeBans.lift`.
 *
 * ⚠️ **وروابطٌ لا أزرار.** التصفيةُ تقعُ على الخادمِ ولها عنوانٌ خاصٌّ بها، فزرٌّ
 * يُصفّي في المتصفّحِ يُنتِجُ قائمةً لا تُشارَكُ ولا تُفهرَسُ ولا يُرجَعُ إليها
 * بزرِّ الرجوع — والصفحةُ كلُّها مُصيَّرةٌ على الخادمِ لأنّ زاحفاً بلا JavaScript
 * يجبُ أن يقرأَها (SC-016).
 *
 * ⚠️ **ولا خريطةَ slug→أيقونة.** التصنيفاتُ صفوفٌ حرّةٌ لكلِّ مساحةِ عمل، فخريطةٌ
 * تُصيبُ ما كتبَه من عرفَها وتُخطئُ كلَّ ما عداه — نفسُ القرارِ المكتوبِ في
 * `ArticleCard`. أيقونةٌ واحدةٌ للصفِّ كلِّه، وهي زخرفةٌ مُعلَنة.
 *
 * ⚠️ **و`flex-wrap` لا تمريرٌ أفقيّ**: شريطٌ يُمرَّرُ يُخفي نصفَ خياراتِه خلفَ
 * إيماءةٍ لا شيءَ يدلُّ عليها، وفي RTL يبدأُ عندَ الحافّةِ الخطأِ في متصفّحاتٍ
 * بعينِها. ستُّ شرائحَ تتّسِعُ في سطرٍ على الحاسوبِ وسطرَينِ على الهاتف.
 */
export function TopicRail({
  categories,
  tags,
  activeCategory,
  activeTag,
}: {
  categories: ArticleTopic[];
  tags: ArticleTopic[];
  activeCategory?: string;
  activeTag?: string;
}) {
  // لا شريطَ لمدوّنةٍ بلا أبواب: صفٌّ فيه «الكل» وحدَها اختيارٌ من واحد.
  if (categories.length === 0 && tags.length === 0) return null;

  const filtered = activeCategory !== undefined || activeTag !== undefined;

  return (
    <nav
      aria-label="تصفية المقالات"
      className="animate-float-in mb-8 rounded-3xl border border-line bg-surface-raised p-4 sm:p-5"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="me-1 flex items-center gap-1.5 text-xs font-bold text-ink-muted">
          <BookIcon className="h-4 w-4" aria-hidden="true" />
          الأبواب
        </span>

        <Chip href="/blog" label="الكل" active={!filtered} />

        {categories.map((topic) => (
          <Chip
            key={topic.slug}
            href={`/blog?category=${topic.slug}`}
            label={topic.name}
            count={topic.articles_count}
            active={topic.slug === activeCategory}
          />
        ))}
      </div>

      {tags.length > 0 ? (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">
          <span className="me-1 flex items-center gap-1.5 text-xs font-bold text-ink-muted">
            <TagIcon className="h-4 w-4" aria-hidden="true" />
            وسوم
          </span>

          {tags.map((topic) => (
            <Chip
              key={topic.slug}
              href={`/blog?tag=${topic.slug}`}
              label={topic.name}
              active={topic.slug === activeTag}
              subtle
            />
          ))}
        </div>
      ) : null}
    </nav>
  );
}

function Chip({
  href,
  label,
  count,
  active,
  subtle = false,
}: {
  href: string;
  label: string;
  count?: number;
  active: boolean;
  subtle?: boolean;
}) {
  /*
    ⚠️ `aria-current="page"` لا لونٌ وحدَه: الشريحةُ النشطةُ مميَّزةٌ بالخلفيّةِ
    للعينِ، وقارئُ الشاشةِ لا يرى خلفيّة — فبدونَه لا شيءَ يقولُ أيُّ بابٍ
    مفتوحٌ الآن.
  */
  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className={`inline-flex items-center gap-1.5 rounded-full font-semibold transition duration-200 ease-out hover:-translate-y-0.5 active:translate-y-0 active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
        subtle ? "px-3 py-1 text-xs" : "px-4 py-2 text-sm"
      } ${
        active
          ? "bg-primary text-white"
          : "border border-line bg-surface text-ink hover:border-primary hover:text-primary-ink"
      }`}
    >
      {label}

      {count !== undefined ? (
        <span
          className={`rounded-full px-1.5 text-xs font-bold tabular-nums ${
            active ? "bg-white/20 text-white" : "bg-primary-soft text-primary-ink"
          }`}
        >
          {arabicNumber(count)}
        </span>
      ) : null}
    </Link>
  );
}
