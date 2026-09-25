import type { Metadata } from "next";
import Link from "next/link";
import {
  publicApi,
  type ArticleCard as Article,
  type ArticleTopics,
} from "@/lib/public-api";
import { SITE_URL, siteUrl } from "@/lib/site";
import { platformName } from "@/lib/platform";
import { JsonLd } from "@/components/seo/JsonLd";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { PageBanner } from "@/components/ui/PageBanner";
import { ArticleCard } from "@/components/blog/ArticleCard";
import { TopicRail } from "@/components/blog/TopicRail";
import { CtaBand } from "@/components/blog/CtaBand";
import { BookIcon, ChevronEndIcon, ChevronStartIcon } from "@/components/icons";
import { counted } from "@/lib/labels";

import { arabicNumber } from "@/lib/numerals";
const TITLE = "المدوّنة";
const DESCRIPTION =
  "مقالات يكتبها مدرّسو المنصّة: خطط مراجعة، شرح مفاهيم، ونصائح للطلاب وأولياء الأمور.";

export async function generateMetadata(): Promise<Metadata> {
  const name = await platformName();

  return {
    title: TITLE,
    description: DESCRIPTION,
    alternates: { canonical: siteUrl("/blog") },
    openGraph: {
      title: `${TITLE} | ${name}`,
      description: DESCRIPTION,
      url: siteUrl("/blog"),
      type: "website",
      locale: "ar_QA",
      /*
       * ⚠️ صورةُ القسمِ لا غلافُ أحدثِ مقال، **والسببُ تغيّرَ ولم يتغيّرِ القرار**.
       * كانَ مكتوباً هنا أنّ `cms_articles` بلا عمودِ غلاف — وهذا لم يعُدْ صحيحاً
       * منذُ `cover_path`. ما يبقى صحيحاً أنّ هذا عنوانُ **القسم**: غلافٌ يتبعُ
       * أحدثَ مقالٍ يجعلُ بطاقةَ مشاركةِ `/blog` تتبدّلُ مع كلِّ نشرة، فرابطٌ
       * شاركَه أحدُهم الأسبوعَ الماضي يعرضُ اليومَ رسمَ مقالٍ لا علاقةَ له به —
       * ويكلّفُ طلباً إضافيّاً في كلِّ فتحةِ صفحةٍ لبناءِ وسمٍ لا يراه القارئ.
       */
      images: [{ url: `${SITE_URL}/marketplace/banner-about.webp` }],
    },
    twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION },
  };
}

// Matches the marketplace's own TTL, and the article page's. A blog index that
// revalidates on a different clock from the sitemap shows a crawler two
// different lists of the same site within a minute.
export const revalidate = 60;

async function loadArticles(page: number, category?: string, tag?: string) {
  try {
    return await publicApi.articles({
      page: String(page),
      per_page: "12",
      category,
      tag,
    });
  } catch {
    // ⚠️ AN EMPTY LIST, NOT A THROWN PAGE. `/blog` is linked from the footer of
    // every public page, so an API blip would 500 a link the whole site carries.
    // The `EmptyState` below says «لا توجد مقالات» — which is wrong for a minute
    // and recoverable, unlike an error page a crawler caches.
    return null;
  }
}

/**
 * أبوابُ التصفّح.
 *
 * ⚠️ شريطٌ فارغٌ لا صفحةٌ ساقطة، بنفسِ قاعدةِ `loadArticles` فوقَها: `/blog`
 * مرتبطٌ من ذيلِ كلِّ صفحةٍ عامّة، وتعثّرُ طلبٍ ثانويٍّ يجبُ ألّا يُسقِطَ
 * المقالاتِ نفسَها. وهما في `Promise.all` واحدٍ لأنّهما مستقلّان — مسلسلَينِ
 * يُضاعِفانِ زمنَ أوّلِ بايت.
 */
async function loadTopics(): Promise<ArticleTopics> {
  try {
    const { data } = await publicApi.articleTopics();

    return data;
  } catch {
    return { categories: [], tags: [] };
  }
}

/**
 * رابطُ صفحةٍ يحملُ المرشِّحَ معه.
 *
 * ⚠️ `Pagination` المشترَكُ **يكتبُ `/teachers` في كلِّ رابطٍ يبنيه** (قِيسَ في
 * الملفّ نفسِه)، فاستعمالُه هنا يُرسِلُ «الصفحة ٢» من المدوّنةِ إلى قائمةِ
 * المدرّسين. وحتّى مع تعميمِه يبقى الشرطُ الأصليُّ قائماً: روابطُ نصٍّ صريحةٌ
 * ليقرأَ زاحفٌ لا يُشغِّلُ JavaScript الصفحةَ الثانية — وهو كلُّ معنى فهرسٍ
 * مُصفَّحٍ على موقعٍ مفهرَس.
 */
function pageHref(page: number, category?: string, tag?: string): string {
  const params = new URLSearchParams();

  if (category) params.set("category", category);
  if (tag) params.set("tag", tag);
  if (page > 1) params.set("page", String(page));

  const query = params.toString();

  return query === "" ? "/blog" : `/blog?${query}`;
}

export default async function BlogIndexPage({
  searchParams,
}: {
  searchParams: Promise<{ page?: string; category?: string; tag?: string }>;
}) {
  const { page, category, tag } = await searchParams;
  const current = Math.max(1, Number(page ?? 1) || 1);
  const [result, topics, name] = await Promise.all([
    loadArticles(current, category, tag),
    loadTopics(),
    platformName(),
  ]);
  const articles: Article[] = result?.data ?? [];
  const lastPage = result?.meta.last_page ?? 1;
  const total = result?.meta.total ?? 0;

  /*
    ⚠️ الاسمُ من الشريطِ لا السلَغُ من العنوان. كانَ السطرُ يطبعُ `{category ?? tag}`
    أي `study-guide` حرفيّاً في جملةٍ عربيّةٍ يقرؤها طالب — سلَغٌ لاتينيٌّ داخلَ
    نصٍّ عربيٍّ يُعادُ ترتيبُه بقواعدِ bidi فوقَ ذلك. والسلَغُ يبقى بديلاً أخيراً:
    مرشِّحٌ مكتوبٌ بيدٍ في العنوانِ لا يقابلُه بابٌ موجودٌ يجبُ أن يقولَ شيئاً.
  */
  const activeTopic =
    category !== undefined
      ? topics.categories.find((topic) => topic.slug === category)
      : tag !== undefined
        ? topics.tags.find((topic) => topic.slug === tag)
        : undefined;
  const filtered = category !== undefined || tag !== undefined;

  // المقالُ الأوّلُ في الصفحةِ الأولى وبلا مرشِّح: صدارةٌ عريضةٌ ثمّ شبكة. مع
  // مرشِّحٍ لا صدارةَ — القارئُ يبحثُ في قائمةٍ لا يُقدَّمُ له اختيارُنا.
  const isPlainFirstPage = current === 1 && !category && !tag;
  const [lead, ...rest] = articles;

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@type": "Blog",
          name: `${TITLE} | ${name}`,
          description: DESCRIPTION,
          url: siteUrl("/blog"),
          inLanguage: "ar",
          publisher: { "@type": "Organization", name, url: SITE_URL },
          blogPost: articles.map((article) => ({
            "@type": "BlogPosting",
            headline: article.title,
            url: `${SITE_URL}/blog/${encodeURIComponent(article.slug)}`,
            datePublished: article.published_at,
            ...(article.excerpt ? { description: article.excerpt } : {}),
          })),
        }}
      />

      {/*
        ⚠️ فتاتُ الخبزِ بياناتٌ منظَّمةٌ لا شريطٌ مرسوم: محرّكُ البحثِ يعرضُ به
        موضعَ الصفحةِ في الموقعِ بدلَ العنوانِ الخامّ، ومحرّكُ الإجابةِ يقرأُ منه
        علاقةَ الجزءِ بالكلّ. سطرانِ لا يُريانِ ويُغيّرانِ شكلَ النتيجة.
      */}
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@type": "BreadcrumbList",
          itemListElement: [
            { "@type": "ListItem", position: 1, name: "الرئيسية", item: SITE_URL },
            { "@type": "ListItem", position: 2, name: TITLE, item: siteUrl("/blog") },
          ],
        }}
      />

      <PageBanner
        title={TITLE}
        description={DESCRIPTION}
        image="/marketplace/banner-about.webp"
        icon={BookIcon}
      >
        {total > 0 ? (
          <p
            className="banner-rise mt-5 text-sm font-semibold text-white/85"
            style={{ animationDelay: "270ms" }}
          >
            {/*
              ⚠️ لا «‏12 articles»: رقمٌ لاتينيٌّ ونصٌّ لاتينيٌّ داخلَ سطرٍ عربيٍّ
              يُعادُ ترتيبُه بقواعدِ bidi فيُقرَأُ مقلوباً — الدرسُ نفسُه الذي
              كلّفَ عدّادَ المالِ إعادةَ تصميم.
            */}
            {counted(total, {
              one: "مقال منشور",
              two: "مقالان منشوران",
              few: "مقالات منشورة",
              many: "مقالاً منشوراً",
              other: "مقال منشور",
            })}
          </p>
        ) : null}
      </PageBanner>

      <TopicRail
        categories={topics.categories}
        tags={topics.tags}
        activeCategory={category}
        activeTag={tag}
      />

      {filtered ? (
        <p className="mb-6 text-sm text-ink-muted">
          {counted(total, {
            one: "مقال",
            two: "مقالان",
            few: "مقالات",
            many: "مقالاً",
            other: "مقال",
            zero: "لا مقالات",
          })}{" "}
          تحت «{activeTopic?.name ?? category ?? tag}».
        </p>
      ) : null}

      {articles.length === 0 ? (
        /*
          ⚠️ جملتانِ لا واحدة. «لا توجد مقالات بعد» تحتَ مرشِّحٍ تقولُ للقارئِ إنّ
          المدوّنةَ فارغةٌ بينما فيها عشراتُ المقالاتِ في أبوابٍ أخرى — وهي جملةٌ
          تُنهي الزيارة. الفرقُ هو المرشِّحُ نفسُه، وكلُّ حالةٍ تعرضُ مخرجَها.
        */
        filtered ? (
          <EmptyState
            title="لا مقالات في هذا الباب"
            description="جرّبْ باباً آخرَ من الشريط فوق، أو اقرأِ المدوّنةَ كلَّها."
            action={
              <Link href="/blog" className="font-semibold text-primary-ink underline">
                اقرأِ المدوّنةَ كلَّها
              </Link>
            }
          />
        ) : (
          <EmptyState
            title="لا توجد مقالات بعد"
            description="سيظهر هنا ما ينشره المدرّسون. تصفَّحِ المدرّسين في هذه الأثناء."
            action={
              <Link href="/teachers" className="font-semibold text-primary-ink underline">
                تصفَّحِ المدرّسين
              </Link>
            }
          />
        )
      ) : (
        <>
          {isPlainFirstPage && lead ? (
            /*
              شكلانِ خلفَ الصدارةِ لا خلفَ كلِّ بطاقة: أثرٌ يُميّزُ مقالَ اليومِ عن
              الشبكةِ تحتَه. `aria-hidden` و`pointer-events-none` لأنّهما رسمٌ
              خالص، ولا `z-index`: البطاقةُ بعدَهما في ترتيبِ المصدرِ و`relative`،
              فتُرسَمُ فوقَهما بلا سياقِ تكديسٍ جديدٍ يُربِكُ ما تحتَه.
            */
            <div className="relative mb-6 overflow-x-clip">
              <div
                aria-hidden="true"
                className="pointer-events-none absolute -top-16 -start-10 h-56 w-56 rounded-full bg-primary-soft blur-3xl"
              />
              <div
                aria-hidden="true"
                className="pointer-events-none absolute -bottom-14 -end-10 h-48 w-48 rounded-full bg-secondary/10 blur-3xl"
              />

              <div
                className="animate-float-in relative"
                style={{ animationDelay: "60ms" }}
              >
                <ArticleCard article={lead} featured />
              </div>
            </div>
          ) : null}

          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {(isPlainFirstPage ? rest : articles).map((article, index) => (
              <li
                key={article.uuid}
                className="animate-float-in"
                /*
                  تدرّجٌ محدودٌ بثمانيةٍ ثمّ يثبت: بطاقةٌ في آخرِ صفحةٍ من اثنتَي
                  عشرةَ ببطءِ ‏٦٠٠ms تصلُ بعدَ أن ينتهي القارئُ من قراءةِ ما
                  فوقَها. و`animation-fill-mode: both` في الصنفِ نفسِه هو ما يمنعُ
                  الوميضَ في التأخير، وكتلةُ «تقليل الحركة» تُصفِّرُ التأخيرَ
                  والمدّةَ معاً.
                */
                style={{ animationDelay: `${Math.min(index, 7) * 45}ms` }}
              >
                <ArticleCard article={article} />
              </li>
            ))}
          </ul>
        </>
      )}

      {lastPage > 1 ? (
        <nav
          className="mt-10 flex items-center justify-between gap-4"
          aria-label="صفحات المدوّنة"
        >
          {current > 1 ? (
            <Link
              href={pageHref(current - 1, category, tag)}
              rel="prev"
              className="flex items-center gap-1 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink transition hover:border-primary hover:text-primary-ink"
            >
              <ChevronEndIcon className="h-4 w-4" aria-hidden="true" />
              الأحدث
            </Link>
          ) : (
            <span />
          )}

          <span className="text-sm text-ink-muted">
            صفحة {arabicNumber(current)} من{" "}
            {arabicNumber(lastPage)}
          </span>

          {current < lastPage ? (
            <Link
              href={pageHref(current + 1, category, tag)}
              rel="next"
              className="flex items-center gap-1 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink transition hover:border-primary hover:text-primary-ink"
            >
              الأقدم
              <ChevronStartIcon className="h-4 w-4" aria-hidden="true" />
            </Link>
          ) : (
            <span />
          )}
        </nav>
      ) : null}

      <CtaBand
        title="اقرأتَ ما يكفي؟ ابدأْ"
        description="المقالات هنا يكتبها مدرّسون يُدرّسون فعلاً على المنصّة. اختر بابك وابدأ."
      />
    </div>
  );
}
