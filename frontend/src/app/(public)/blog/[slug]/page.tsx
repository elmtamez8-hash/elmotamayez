import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { publicApi, NotFoundError, type ArticleDetail } from "@/lib/public-api";
import { SITE_URL, siteUrl } from "@/lib/site";
import { platformName } from "@/lib/platform";
import { JsonLd, absoluteHttpUrl } from "@/components/seo/JsonLd";
import { CourseCard } from "@/components/marketplace/CourseCard";
import { ArticleBody } from "@/components/blog/ArticleBody";
import { ArticleToc } from "@/components/blog/ArticleToc";
import { CtaBand } from "@/components/blog/CtaBand";
import { counted, formatDate } from "@/lib/labels";
import {
  isoMinutes,
  readingMinutes,
  withHeadingAnchors,
  wordCount,
} from "@/lib/article";
import {
  AcademicCapIcon,
  ChevronDownIcon,
  ChevronEndIcon,
  ChevronStartIcon,
  ClockIcon,
  CoursesIcon,
  QuestionIcon,
  SparkIcon,
  TagIcon,
} from "@/components/icons";

type Params = { slug: string };

export const revalidate = 60;

async function loadArticle(slug: string): Promise<ArticleDetail> {
  try {
    /*
     * ⚠️ **يُفَكُّ الترميزُ قبلَ التمرير، وغيابُ هذا السطرِ قتلَ كلَّ مقالٍ على
     * المنصّة.** Next يُسلِّمُ جزءَ المسارِ **مُرمَّزاً** — `courses/[slug]` و
     * `teachers/[slug]` كلاهما يفكُّه قبلَ المقارنة — و`publicApi.article()`
     * يُرمِّزُ ما يصلُه، فالمُرمَّزُ يُرمَّزُ ثانيةً: `%D8%A3` تصيرُ `%25D8%A3`،
     * ويصلُ الخادمَ سلَغٌ لا وجودَ له ⇒ ‏٤٠٤ على مقالٍ منشور. قِيسَ على الإنتاج
     * ٢٠٢٦-٠٩-١٣: الترميزُ مرّةً ‏٢٠٠ ومرّتَينِ ‏٤٠٤، وصفحاتُ المقالاتِ الستُّ
     * كلُّها كانت ‏٤٠٤ بينما واجهةُ البرمجةِ تجيبُ ‏٢٠٠.
     *
     * ⚠️ **والسلَغُ اللاتينيُّ يُخفي العطبَ تماماً**: `encodeURIComponent` على
     * ASCII لا يُغيّرُ شيئاً، فالترميزُ المزدوجُ لا أثرَ له على `/courses` و
     * `/teachers` — والمدوّنةُ أوّلُ مكانٍ سلَغُه عربيّ. وفكُّ ترميزِ نصٍّ عربيٍّ
     * مفكوكٍ أصلاً لا يفعلُ شيئاً، فالسطرُ صحيحٌ في الحالتَين.
     */
    const { data } = await publicApi.article(decodeURIComponent(slug));

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
      article.seo_description ?? article.excerpt ?? `مقال على مدوّنة ${name}.`;
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
      /*
       * ⚠️ الوسمُ يُكتَبُ **حينَ يُمنَعُ فقط**. `robots: { index: true }` يُصيَّرُ
       * وسماً صريحاً، وصفحةٌ تُصرِّحُ بأنّها مفهرَسةٌ لا تكسبُ شيئاً — بينما
       * `noindex` مكتوبٌ بالخطأِ يُخفي المقالَ بلا أثرٍ في أيِّ سجلّ. الغيابُ هو
       * الافتراضُ الصحيح.
       */
      ...(article.is_indexable
        ? {}
        : { robots: { index: false, follow: true } }),
      openGraph: {
        title,
        description,
        url,
        type: "article",
        locale: "ar_QA",
        siteName: name,
        publishedTime: article.published_at,
        modifiedTime: article.updated_at,
        /*
         * ⚠️ غلافُ المقالِ إن وُجِد، وصورةُ القسمِ وإلّا. بطاقةُ مشاركةٍ بلا صورةٍ
         * شريطٌ رماديٌّ على كلِّ منصّةِ تواصل، فالارتدادُ ليس زخرفة.
         */
        images: [
          {
            url:
              article.cover_url ?? `${SITE_URL}/marketplace/banner-about.webp`,
          },
        ],
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
  /*
    الشرطُ نفسُه الذي يردُّ به `ArticleToc` لا شيء، مقروءاً هنا لأنّ **الشبكةَ**
    تعتمدُ عليه: عمودٌ محجوزٌ لفهرسٍ لا يُصيَّرُ هو فراغٌ بجانبِ النصّ. وهجاءٌ
    ثانٍ للقاعدةِ داخلَ المكوّنِ يفترقُ عن هذا عندَ أوّلِ تعديل — فالأفضلُ أن
    يُقرأَ العددُ هنا ويبقى القرارُ واحداً.
  */
  const hasToc = headings.length >= 2;

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
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
          // نفسُ الحقلِ الذي يُعرَضُ في صندوقِ «باختصار». ما يُقرَأُ هو ما يُقتبَس.
          ...(article.summary ? { abstract: article.summary } : {}),
          ...(article.cover_url ? { image: article.cover_url } : {}),
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
            {
              "@type": "ListItem",
              position: 1,
              name: "الرئيسية",
              item: SITE_URL,
            },
            {
              "@type": "ListItem",
              position: 2,
              name: "المدوّنة",
              item: siteUrl("/blog"),
            },
            {
              "@type": "ListItem",
              position: 3,
              name: article.title,
              item: url,
            },
          ],
        }}
      />

      <nav aria-label="مسار التصفّح" className="mb-8">
        <Link
          href="/blog"
          className="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface-raised px-4 py-2 text-sm font-bold text-primary-ink shadow-sm transition duration-200 ease-out hover:border-primary hover:bg-primary hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
        >
          <ChevronEndIcon className="h-4 w-4" aria-hidden="true" />
          المدوّنة
        </Link>
      </nav>

      {article.cover_url ? (
        /*
          ⚠️ ‏٢١:٩ لا ‏١٦:٩ **لأنّ العمودَ اتّسع**. المقاسانِ متساويانِ في صفحةٍ
          ضيّقة، وفي ‏١١٥٢ بكسلاً يصيرُ ‏١٦:٩ سلاباً ارتفاعُه ‏٦٤٨ فوقَ العنوان —
          أي مقالٌ يبدأُ تحتَ الطيّة. والأغلفةُ مرسومةٌ ‏١٦:٩ وحركتُها في المنتصفِ
          عمداً، فالقصُّ هنا ‏١٠٧ بكسلاً من أعلى ومثلُها من أسفل ولا يمسُّ الرسم.
        */
        <div className="banner-rise mb-10 aspect-[21/9] overflow-hidden rounded-3xl border border-line bg-primary-soft shadow-xl shadow-primary/10">
          {/*
            ⚠️ `<img>` لا `next/image`: المسارُ يكتبُه مدرّسٌ من اللوحة، أي مدخلٌ
            غيرُ حرفيّ — وملاحظةُ هذا المستودعِ عن تحذيراتِ npm تقولُ إنّ ثغرةَ
            `sharp` تصيرُ حيّةً عندَ أوّلِ مسارٍ كهذا يصلُ `next/image`.
            و`priority` لا معنى له هنا: هذه أكبرُ عنصرٍ فوقَ الطيّ، فلا تحميلَ
            كسولاً عليها.
          */}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={article.cover_url}
            alt=""
            aria-hidden="true"
            className="h-full w-full object-cover"
          />
        </div>
      ) : null}

      <header className="banner-rise mb-10 max-w-4xl">
        <div className="mb-4 flex flex-wrap items-center gap-3">
          {article.category ? (
            <Link
              href={`/blog?category=${article.category.slug}`}
              className="rounded-full bg-primary px-3.5 py-1 text-xs font-bold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
            >
              {article.category.name}
            </Link>
          ) : null}

          <time
            dateTime={article.published_at}
            className="text-sm font-semibold text-ink-muted"
          >
            {formatDate(article.published_at)}
          </time>

          {/*
            ⚠️ «‏٥ دقائق قراءة» لا «5 min read»: رقمٌ ونصٌّ لاتينيّانِ داخلَ سطرٍ
            عربيٍّ يُعادُ ترتيبُهما بقواعدِ bidi.
          */}
          <span className="flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-primary-ink">
            <ClockIcon className="h-4 w-4" aria-hidden="true" />
            {counted(minutes, {
              one: "دقيقة قراءة",
              two: "دقيقتا قراءة",
              few: "دقائق قراءة",
              many: "دقيقة قراءة",
              other: "دقيقة قراءة",
            })}
          </span>
        </div>

        <h1 className="text-balance text-4xl font-extrabold leading-tight text-ink sm:text-5xl">
          {article.title}
        </h1>

        {article.excerpt ? (
          /*
            ⚠️ المقتطفُ خلاصةٌ مقروءةٌ لا زخرفة: محرّكُ الإجابةِ يقتبسُ أوّلَ فقرةٍ
            مكتفيةٍ بنفسِها، فوضعُها أوّلاً وبخطٍّ أكبرَ يخدمُ القارئَ والآلةَ معاً.
            يُميَّزُ بالحجمِ لا بحدٍّ جانبيٍّ ملوَّن، والإزاحاتُ منطقيّةٌ (`ps-`) إن عادت.
          */
          <p className="mt-5 text-xl leading-relaxed text-ink-muted">
            {article.excerpt}
          </p>
        ) : null}
      </header>

      {article.summary ? (
        /*
          ⚠️ **صندوقُ «باختصار» هو نصفُ AEO المرئيّ.** محرّكُ الإجابةِ يقتبسُ فقرةً
          مكتفيةً بنفسِها، وهذه هي — والقارئُ المستعجلُ يأخذُ الجوابَ في سطرَين
          بدلَ أن يغادر. الحقلُ نفسُه يُرسَلُ في `abstract` أدناه، فما يُقرَأُ هو
          ما يُقتبَس: نصّانِ مختلفانِ لغرضٍ واحدٍ يفترقانِ عندَ أوّلِ تحرير.
        */
        <section
          aria-labelledby="summary-heading"
          className="relative isolate my-10 overflow-hidden rounded-3xl border border-primary/20 bg-primary-soft p-6 shadow-sm sm:p-8"
        >
          <SparkIcon className="pointer-events-none absolute -bottom-8 -end-8 -z-10 h-40 w-40 text-primary-ink/10" />
          <h2
            id="summary-heading"
            className="mb-3 flex items-center gap-2.5 text-base font-extrabold text-primary-ink"
          >
            <span
              aria-hidden="true"
              className="grid h-9 w-9 place-items-center rounded-xl bg-primary text-white"
            >
              <SparkIcon className="h-5 w-5" />
            </span>
            باختصار
          </h2>
          <p className="max-w-3xl text-lg leading-relaxed text-ink">
            {article.summary}
          </p>
        </section>
      ) : null}

      {/*
        ⚠️ **عمودانِ على الشاشةِ الواسعة، وترتيبُ المصدرِ هو ترتيبُ الهاتف.**
        الفهرسُ أوّلاً في DOM فيقرؤه صاحبُ الهاتفِ قبلَ النصِّ كما كانَ تماماً،
        و`lg:col-start-2` يضعُه يميناً على الحاسوبِ بلا أن يتبدّلَ الترتيب — وضعُ
        الشبكةِ مستقلٌّ عن ترتيبِ المصدر. وبغيرِ ذلك: فهرسٌ **بعدَ** المقالِ على
        الهاتف، وهو فهرسٌ لا يفيدُ أحداً.

        ⚠️ و«باختصار» فوقَ الشبكةِ بعرضِ الصفحةِ كلِّها: هو ما يقتبسُه محرّكُ
        الإجابةِ وما يقرؤه المستعجل، فلا يُزحَمُ في عمود.

        ⚠️ والشبكةُ لا تُفتَحُ أصلاً بلا فهرس: `ArticleToc` يردُّ لا شيءَ لعنوانٍ
        واحد، فعمودٌ محجوزٌ بعرضِ ‏١٩rem يصيرُ فراغاً بجانبِ النصِّ بلا سبب.
      */}
      <div
        className={
          hasToc
            ? "lg:grid lg:grid-cols-[minmax(0,1fr)_19rem] lg:items-start lg:gap-10"
            : ""
        }
      >
        {hasToc ? (
          <aside className="lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24">
            <ArticleToc headings={headings} />
          </aside>
        ) : null}

        <div className="min-w-0 lg:col-start-1 lg:row-start-1">
          {/* The API renders the Markdown and STRIPS raw HTML at the parse rather
          than escaping it, so the tag allowlist is the Markdown feature set
          itself — there is nothing to configure here and no `body` field to
          render by mistake. `prose-article` is the typography rule in
          globals.css; this component sets no colours of its own. */}
          <ArticleBody html={html} />

          {article.tags && article.tags.length > 0 ? (
            <ul className="mt-10 flex flex-wrap items-center gap-2">
              <li aria-hidden="true">
                <TagIcon className="h-4 w-4 text-ink-muted" />
              </li>
              {article.tags.map((tag) => (
                <li key={tag.slug}>
                  {/* وسمٌ يُنقَرُ: `?tag=` مدعومٌ في الواجهةِ الخلفيّةِ سلفاً. */}
                  <Link
                    href={`/blog?tag=${tag.slug}`}
                    className="inline-flex rounded-full border border-line bg-surface-raised px-3 py-1 text-xs font-semibold text-ink transition hover:border-primary hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
                  >
                    {tag.name}
                  </Link>
                </li>
              ))}
            </ul>
          ) : null}

          {article.faq.length > 0 ? (
            <section
              aria-labelledby="faq-heading"
              className="mt-14 border-t border-line pt-10"
            >
              <h2
                id="faq-heading"
                className="mb-6 flex items-center gap-3 text-2xl font-extrabold text-ink sm:text-3xl"
              >
                <span
                  aria-hidden="true"
                  className="grid h-10 w-10 place-items-center rounded-xl bg-primary-soft text-primary-ink"
                >
                  <QuestionIcon className="h-5 w-5" />
                </span>
                أسئلة شائعة
              </h2>

              {/*
            ⚠️ `<details>` الأصليّ لا مطواةٌ بـJavaScript: يفتحُ ويغلقُ بلا شيفرة،
            ويحملُ دورَه ووصولَه من المتصفّح، **ونصُّه في DOM حتّى وهو مطويّ** —
            فيقرؤه الزاحفُ ومحرّكُ الإجابةِ ويجدُه بحثُ الصفحة. مطواةٌ تُصيِّرُ
            الجوابَ عندَ النقرِ تُخفيه عن الثلاثة.
          */}
              <ul className="space-y-3">
                {article.faq.map((entry) => (
                  <li key={entry.question}>
                    <details className="group rounded-2xl border border-line bg-surface-raised p-5 shadow-sm transition hover:border-primary/40 open:border-primary/40 open:shadow-md open:shadow-primary/10 motion-reduce:transition-none">
                      <summary className="flex cursor-pointer items-center justify-between gap-3 font-bold text-ink">
                        {entry.question}
                        <span
                          aria-hidden="true"
                          className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary-soft text-primary-ink transition duration-200 group-open:bg-primary group-open:text-white motion-reduce:transition-none"
                        >
                          <ChevronDownIcon className="h-4 w-4 transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" />
                        </span>
                      </summary>
                      <p className="mt-3 leading-relaxed text-ink-muted">
                        {entry.answer}
                      </p>
                    </details>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          {article.faq.length > 0 ? (
            /*
          ⚠️ **`FAQPage` يُرسَلُ فقط حينَ يوجدُ القسمُ المرئيّ**، وكلاهما من نفسِ
          المصفوفة. بياناتٌ منظَّمةٌ تصفُ أسئلةً ليست على الصفحةِ مخالفةٌ صريحةٌ
          تُعاقِبُ عليها المحرّكاتُ لا تُكافئ — والزوجُ الناقصُ مُصفّىً في الخلفيّةِ
          قبلَ أن يصلَ أيّاً منهما.
        */
            <JsonLd
              data={{
                "@context": "https://schema.org",
                "@type": "FAQPage",
                mainEntity: article.faq.map((entry) => ({
                  "@type": "Question",
                  name: entry.question,
                  acceptedAnswer: { "@type": "Answer", text: entry.answer },
                })),
              }}
            />
          ) : null}
        </div>
      </div>

      {article.related_teachers.length > 0 ? (
        <section className="mt-14 border-t border-line pt-10">
          <h2 className="mb-6 flex items-center gap-3 text-2xl font-extrabold text-ink sm:text-3xl">
            <span
              aria-hidden="true"
              className="grid h-10 w-10 place-items-center rounded-xl bg-primary-soft text-primary-ink"
            >
              <AcademicCapIcon className="h-5 w-5" />
            </span>
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
                  className="group flex h-full items-center gap-4 rounded-3xl border border-line bg-surface-raised p-5 shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                >
                  <span
                    aria-hidden="true"
                    className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary-ink transition duration-300 ease-out group-hover:bg-primary group-hover:text-white motion-reduce:transition-none"
                  >
                    <AcademicCapIcon className="h-6 w-6" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block font-extrabold text-ink">{teacher.name}</span>
                    {teacher.headline ? (
                      <span className="mt-1 block text-sm text-ink-muted">
                        {teacher.headline}
                      </span>
                    ) : null}
                  </span>
                  <ChevronStartIcon className="h-5 w-5 shrink-0 text-primary-ink transition duration-300 ease-out group-hover:-translate-x-1 motion-reduce:transition-none motion-reduce:group-hover:translate-x-0" />
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {article.related_courses.length > 0 ? (
        <section className="mt-12">
          <h2 className="mb-6 flex items-center gap-3 text-2xl font-extrabold text-ink sm:text-3xl">
            <span
              aria-hidden="true"
              className="grid h-10 w-10 place-items-center rounded-xl bg-primary-soft text-primary-ink"
            >
              <CoursesIcon className="h-5 w-5" />
            </span>
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
        <Link
          href="/blog"
          className="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-5 py-2.5 font-bold text-primary-ink transition hover:bg-primary hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
        >
          <ChevronEndIcon className="h-4 w-4" />
          كلّ المقالات
        </Link>
      </p>
    </div>
  );
}
