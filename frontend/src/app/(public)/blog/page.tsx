import type { Metadata } from "next";
import Link from "next/link";
import { publicApi, type ArticleCard } from "@/lib/public-api";
import { SITE_URL, siteUrl } from "@/lib/site";
import { PLATFORM_NAME } from "@/lib/platform";
import { JsonLd } from "@/components/seo/JsonLd";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { formatDate } from "@/lib/labels";

const TITLE = "المدوّنة";
const DESCRIPTION =
  "مقالات يكتبها مدرّسو المنصّة: خطط مراجعة، شرح مفاهيم، ونصائح للطلاب وأولياء الأمور.";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: siteUrl("/blog") },
  openGraph: {
    title: `${TITLE} | ${PLATFORM_NAME}`,
    description: DESCRIPTION,
    url: siteUrl("/blog"),
    type: "website",
    locale: "ar_QA",
  },
  twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION },
};

// Matches the marketplace's own TTL, and the article page's. A blog index that
// revalidates on a different clock from the sitemap shows a crawler two
// different lists of the same site within a minute.
export const revalidate = 60;

async function loadArticles(page: number) {
  try {
    return await publicApi.articles({ page: String(page), per_page: "12" });
  } catch {
    // ⚠️ AN EMPTY LIST, NOT A THROWN PAGE. `/blog` is linked from the footer of
    // every public page, so an API blip would 500 a link the whole site carries.
    // The `EmptyState` below says «لا توجد مقالات» — which is wrong for a minute
    // and recoverable, unlike an error page a crawler caches.
    return null;
  }
}

export default async function BlogIndexPage({
  searchParams,
}: {
  searchParams: Promise<{ page?: string }>;
}) {
  const { page } = await searchParams;
  const current = Math.max(1, Number(page ?? 1) || 1);
  const result = await loadArticles(current);
  const articles: ArticleCard[] = result?.data ?? [];
  const lastPage = result?.meta.last_page ?? 1;

  return (
    <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6">
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@type": "Blog",
          name: `${TITLE} | ${PLATFORM_NAME}`,
          description: DESCRIPTION,
          url: siteUrl("/blog"),
          inLanguage: "ar",
          blogPost: articles.map((article) => ({
            "@type": "BlogPosting",
            headline: article.title,
            url: `${SITE_URL}/blog/${encodeURIComponent(article.slug)}`,
            datePublished: article.published_at,
          })),
        }}
      />

      <header className="mb-10">
        <h1 className="text-3xl font-extrabold text-ink">{TITLE}</h1>
        <p className="mt-2 text-ink-muted">{DESCRIPTION}</p>
      </header>

      {articles.length === 0 ? (
        <EmptyState
          title="لا توجد مقالات بعد"
          description="سيظهر هنا ما ينشره المدرّسون. تصفَّحِ المدرّسين في هذه الأثناء."
          action={
            <Link href="/teachers" className="font-semibold text-primary underline">
              تصفَّحِ المدرّسين
            </Link>
          }
        />
      ) : (
        <ul className="space-y-4">
          {articles.map((article) => (
            <li key={article.uuid}>
              <Card as="article">
                <div className="mb-2 flex flex-wrap items-center gap-2">
                  {article.category ? (
                    <Badge tone="neutral">{article.category.name}</Badge>
                  ) : null}
                  <time
                    dateTime={article.published_at}
                    className="text-xs text-ink-muted"
                  >
                    {formatDate(article.published_at)}
                  </time>
                </div>

                <h2 className="text-lg font-bold text-ink">
                  {/* The href is NOT pre-encoded: Next encodes a `href` string
                      itself, and encoding twice turns `%D8%AE` into `%25D8%AE`
                      and every Arabic link into a 404. */}
                  <Link href={`/blog/${article.slug}`} className="hover:underline">
                    {article.title}
                  </Link>
                </h2>

                {article.excerpt ? (
                  <p className="mt-2 text-sm leading-relaxed text-ink-muted">
                    {article.excerpt}
                  </p>
                ) : null}
              </Card>
            </li>
          ))}
        </ul>
      )}

      {lastPage > 1 ? (
        // Plain links, so a crawler that runs no JavaScript reaches page two —
        // which is the whole point of a paginated index on an indexed site.
        <nav className="mt-10 flex items-center justify-between" aria-label="صفحات المدوّنة">
          {current > 1 ? (
            <Link href={`/blog?page=${current - 1}`} className="font-semibold text-primary">
              الأحدث
            </Link>
          ) : (
            <span />
          )}
          <span className="text-sm text-ink-muted">
            صفحة {current} من {lastPage}
          </span>
          {current < lastPage ? (
            <Link href={`/blog?page=${current + 1}`} className="font-semibold text-primary">
              الأقدم
            </Link>
          ) : (
            <span />
          )}
        </nav>
      ) : null}
    </div>
  );
}
