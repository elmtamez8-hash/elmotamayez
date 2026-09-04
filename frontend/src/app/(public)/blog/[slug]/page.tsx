import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import {
  publicApi,
  NotFoundError,
  type ArticleDetail,
} from "@/lib/public-api";
import { SITE_URL, siteUrl } from "@/lib/site";
import { platformName } from "@/lib/platform";
import { JsonLd, absoluteHttpUrl } from "@/components/seo/JsonLd";
import { Badge } from "@/components/ui/Badge";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { ArticleToc } from "@/components/blog/ArticleToc";
import { CtaBand } from "@/components/blog/CtaBand";
import { formatDate } from "@/lib/labels";
import {
  isoMinutes,
  readingMinutes,
  withHeadingAnchors,
  wordCount,
} from "@/lib/article";
import {
  AcademicCapIcon,
  ChevronEndIcon,
  ClockIcon,
  CoursesIcon,
  TagIcon,
} from "@/components/icons";

type Params = { slug: string };

export const revalidate = 60;

async function loadArticle(slug: string): Promise<ArticleDetail> {
  try {
    const { data } = await publicApi.article(slug);

    return data;
  } catch (error) {
    // The API answers 404 identically for «no such article», «still a draft»,
    // «scheduled», «deleted» and «the workspace never opted into public
    // publishing». Preserve that here: a distinct page for any of them would
    // confirm that an unpublished article exists at a guessed address.
    if (error instanceof NotFoundError) notFound();
    throw error;
  }
}

export async function generateMetadata({
  params,
}: {
  params: Promise<Params>;
}): Promise<Metadata> {
  const { slug } = await params;
  const name = await platformName();

  try {
    const article = await loadArticle(slug);
    const title = article.seo_title ?? article.title;
    const description =
      article.seo_description ??
      article.excerpt ??
      `مقال على مدوّنة ${name}.`;
    const url = `${SITE_URL}/blog/${encodeURIComponent(article.slug)}`;

    return {
      title,
      description,
      /*
       * ⚠️ THE TEACHER'S `canonical_url` IS CHECKED BEFORE IT IS USED. The API
       * validates it as an absolute http(s) URL today; the rows already in the
       * table predate that rule, and a relative or `javascript:` value here is a
       * broken canonical — the one tag that tells a search engine which address
       * of a page to keep. A bad one is worse than none, so it falls back to our
       * own address rather than being emitted as typed.
       */
      alternates: { canonical: absoluteHttpUrl(article.canonical_url) ?? url },
      openGraph: {
        title,
        description,
        url,
        type: "article",
        locale: "ar_QA",
        siteName: name,
        publishedTime: article.published_at,
        modifiedTime: article.updated_at,
        // ⚠️ لا غلافَ للمقالِ في الجدول، وبطاقةُ مشاركةٍ بلا صورةٍ شريطٌ رماديّ:
        // صورةُ القسمِ أصدقُ من لا شيء، وأصدقُ من غلافٍ مخترَع.
        images: [{ url: `${SITE_URL}/marketplace/banner-about.webp` }],
        ...(article.tags && article.tags.length > 0
          ? { tags: article.tags.map((tag) => tag.name) }
          : {}),
      },
      twitter: { card: "summary_large_image", title, description },
    };
  } catch {
    return { title: "غير متاح" };
  }
}

export default async function ArticlePage({
  params,
}: {
  params: Promise<Params>;
}) {
  const { slug } = await params;
  const article = await loadArticle(slug);
  const url = `${SITE_URL}/blog/${encodeURIComponent(article.slug)}`;
  const name = await platformName();

  /*
   * ⚠️ المِرساةُ والفهرسُ من نداءٍ واحد. الدالّةُ تحقنُ `id` في كلِّ عنوانٍ
   * وتُعيدُ القائمةَ نفسَها التي حقنَتها، فلا يفترقُ الفهرسُ عن النصِّ عندَ أوّلِ
   * عنوانٍ مكرَّر. {@see import("@/lib/article").withHeadingAnchors}
   */
  const { html, headings } = withHeadingAnchors(article.body_html);
  const minutes = readingMinutes(article.body_html);

  return (
    <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@type": "BlogPosting",
          headline: article.title,
          description: article.seo_description ?? article.excerpt ?? undefined,
          datePublished: article.published_at,
          dateModified: article.updated_at,
          inLanguage: "ar",
          mainEntityOfPage: {
            "@type": "WebPage",
            "@id": absoluteHttpUrl(article.canonical_url) ?? url,
          },
          /*
           * ⚠️ NO `author`, AND THAT IS FR-034 RATHER THAN AN OMISSION. The
           * article's `author_id` names a `users` row and nothing on `users` is
           * public. The name a reader wants is the teacher's, which the related
           * block below carries from the marketplace — where it is published by
           * decision rather than inherited into a structured-data block.
           */
          publisher: { "@type": "Organization", name, url: SITE_URL },
          /*
           * ⚠️ حقولٌ تقرؤها محرّكاتُ الإجابةِ حرفيّاً، وكلُّها **مقيسةٌ من النصِّ
           * نفسِه** لا مُعلَنةٌ بالتمنّي: `wordCount` و`timeRequired` مشتقّانِ من
           * `body_html` بالدالّةِ التي يُعرَضُ بها الرقمُ على الشاشة — فما يُقالُ
           * للآلةِ هو ما يراه القارئُ. رقمانِ من مصدرَينِ يفترقانِ، وحينَها يكونُ
           * أحدُهما كذباً منظَّماً.
           */
          wordCount: wordCount(article.body_html),
          timeRequired: isoMinutes(minutes),
          isAccessibleForFree: true,
          ...(article.category
            ? { articleSection: article.category.name }
            : {}),
          ...(article.tags && article.tags.length > 0
            ? { keywords: article.tags.map((tag) => tag.name).join("، ") }
            : {}),
        }}
      />

      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@type": "BreadcrumbList",
          itemListElement: [
            { "@type": "ListItem", position: 1, name: "الرئيسية", item: SITE_URL },
            { "@type": "ListItem", position: 2, name: "المدوّنة", item: siteUrl("/blog") },
            { "@type": "ListItem", position: 3, name: article.title, item: url },
          ],
        }}
      />

      <nav aria-label="مسار التصفّح" className="mb-6">
        <Link
          href="/blog"
          className="inline-flex items-center gap-1 text-sm font-semibold text-primary-ink hover:underline"
        >
          <ChevronEndIcon className="h-4 w-4" aria-hidden="true" />
          المدوّنة
        </Link>
      </nav>

      <header className="banner-rise mb-8">
        <div className="mb-4 flex flex-wrap items-center gap-3">
          {article.category ? (
            <Link href={`/blog?category=${article.category.slug}`}>
              <Badge tone="neutral">{article.category.name}</Badge>
            </Link>
          ) : null}

          <time dateTime={article.published_at} className="text-sm text-ink-muted">
            {formatDate(article.published_at)}
          </time>

          {/*
            ⚠️ «‏٥ دقائق قراءة» لا «5 min read»: رقمٌ ونصٌّ لاتينيّانِ داخلَ سطرٍ
            عربيٍّ يُعادُ ترتيبُهما بقواعدِ bidi.
          */}
          <span className="flex items-center gap-1 text-sm text-ink-muted">
            <ClockIcon className="h-4 w-4" aria-hidden="true" />
            {minutes.toLocaleString("ar-QA")} دقائق قراءة
          </span>
        </div>

        <h1 className="text-3xl font-extrabold leading-tight text-ink sm:text-4xl">
          {article.title}
        </h1>

        {article.excerpt ? (
          /*
            ⚠️ المقتطفُ خلاصةٌ مقروءةٌ لا زخرفة: محرّكُ الإجابةِ يقتبسُ أوّلَ فقرةٍ
            مكتفيةٍ بنفسِها، فوضعُها أوّلاً وبخطٍّ أكبرَ يخدمُ القارئَ والآلةَ معاً.
            الحدُّ الجانبيُّ منطقيٌّ (`border-s`) لا `border-l`.
          */
          <p className="mt-4 border-s-4 border-primary/30 ps-4 text-lg leading-relaxed text-ink-muted">
            {article.excerpt}
          </p>
        ) : null}
      </header>

      <ArticleToc headings={headings} />

      {/* The API renders the Markdown and STRIPS raw HTML at the parse rather
          than escaping it, so the tag allowlist is the Markdown feature set
          itself — there is nothing to configure here and no `body` field to
          render by mistake. `prose-article` is the typography rule in
          globals.css; this component sets no colours of its own. */}
      <div className="prose-article" dangerouslySetInnerHTML={{ __html: html }} />

      {article.tags && article.tags.length > 0 ? (
        <ul className="mt-10 flex flex-wrap items-center gap-2">
          <li aria-hidden="true">
            <TagIcon className="h-4 w-4 text-ink-muted" />
          </li>
          {article.tags.map((tag) => (
            <li key={tag.slug}>
              {/* وسمٌ يُنقَرُ: `?tag=` مدعومٌ في الواجهةِ الخلفيّةِ سلفاً. */}
              <Link href={`/blog?tag=${tag.slug}`}>
                <Badge tone="neutral">{tag.name}</Badge>
              </Link>
            </li>
          ))}
        </ul>
      ) : null}

      {article.related_teachers.length > 0 ? (
        <section className="mt-14 border-t border-line pt-10">
          <h2 className="mb-5 flex items-center gap-2 text-xl font-bold text-ink">
            <AcademicCapIcon className="h-5 w-5 text-primary-ink" aria-hidden="true" />
            مدرّسون في هذا التخصّص
          </h2>

          <ul className="grid gap-3 sm:grid-cols-2">
            {article.related_teachers.map((teacher, index) => (
              <li
                key={teacher.uuid}
                className="animate-float-in"
                style={{ animationDelay: `${Math.min(index, 5) * 45}ms` }}
              >
                <Link
                  href={`/teachers/${teacher.slug ?? teacher.uuid}`}
                  className="flex h-full flex-col rounded-2xl border border-line bg-surface-raised p-4 transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                >
                  <span className="font-semibold text-ink">{teacher.name}</span>
                  {teacher.headline ? (
                    <span className="mt-1 text-sm text-ink-muted">
                      {teacher.headline}
                    </span>
                  ) : null}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {article.related_courses.length > 0 ? (
        <section className="mt-12">
          <h2 className="mb-5 flex items-center gap-2 text-xl font-bold text-ink">
            <CoursesIcon className="h-5 w-5 text-primary-ink" aria-hidden="true" />
            كورسات ذات صلة
          </h2>
          <div className="grid gap-4 sm:grid-cols-2">
            {article.related_courses.map((course) => (
              <CourseCard key={course.uuid} course={course} />
            ))}
          </div>
        </section>
      ) : null}

      <CtaBand />

      <p className="mt-10">
        <Link href="/blog" className="font-semibold text-primary-ink underline">
          كلّ المقالات
        </Link>
      </p>
    </div>
  );
}
