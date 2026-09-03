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
import { formatDate } from "@/lib/labels";

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

  return (
    <article className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
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
          publisher: { "@type": "Organization", name: await platformName(), url: SITE_URL },
        }}
      />

      <header className="mb-8">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          {article.category ? (
            <Badge tone="neutral">{article.category.name}</Badge>
          ) : null}
          <time dateTime={article.published_at} className="text-sm text-ink-muted">
            {formatDate(article.published_at)}
          </time>
        </div>

        <h1 className="text-3xl font-extrabold leading-tight text-ink">
          {article.title}
        </h1>

        {article.excerpt ? (
          <p className="mt-3 text-lg leading-relaxed text-ink-muted">
            {article.excerpt}
          </p>
        ) : null}
      </header>

      {/* The API renders the Markdown and STRIPS raw HTML at the parse rather
          than escaping it, so the tag allowlist is the Markdown feature set
          itself — there is nothing to configure here and no `body` field to
          render by mistake. `prose-article` is the typography rule in
          globals.css; this component sets no colours of its own. */}
      <div
        className="prose-article"
        dangerouslySetInnerHTML={{ __html: article.body_html }}
      />

      {article.tags && article.tags.length > 0 ? (
        <ul className="mt-10 flex flex-wrap gap-2">
          {article.tags.map((tag) => (
            <li key={tag.slug}>
              <Badge tone="neutral">{tag.name}</Badge>
            </li>
          ))}
        </ul>
      ) : null}

      {article.related_teachers.length > 0 ? (
        <section className="mt-14 border-t border-line pt-10">
          <h2 className="mb-4 text-xl font-bold text-ink">مدرّسون في هذا التخصّص</h2>
          <ul className="space-y-3">
            {article.related_teachers.map((teacher) => (
              <li key={teacher.uuid}>
                <Link
                  href={`/teachers/${teacher.slug ?? teacher.uuid}`}
                  className="flex flex-col rounded-2xl border border-line p-4 hover:border-primary"
                >
                  <span className="font-semibold text-ink">{teacher.name}</span>
                  {teacher.headline ? (
                    <span className="text-sm text-ink-muted">{teacher.headline}</span>
                  ) : null}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {article.related_courses.length > 0 ? (
        <section className="mt-10">
          <h2 className="mb-4 text-xl font-bold text-ink">كورسات ذات صلة</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            {article.related_courses.map((course) => (
              <CourseCard key={course.uuid} course={course} />
            ))}
          </div>
        </section>
      ) : null}

      <p className="mt-14">
        <Link href="/blog" className="font-semibold text-primary-ink underline">
          كلّ المقالات
        </Link>
      </p>
    </article>
  );
}
