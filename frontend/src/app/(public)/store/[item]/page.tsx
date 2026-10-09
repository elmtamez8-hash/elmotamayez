import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArticleBody } from "@/components/blog/ArticleBody";
import { BookIcon, ChevronStartIcon, CoursesIcon, DocumentIcon, StoreIcon, TruckIcon } from "@/components/icons";
import { StoreBuyPanel } from "@/components/store/StoreBuyPanel";
import { Badge } from "@/components/ui/Badge";
import { formatMinorMoney } from "@/lib/labels";
import { NotFoundError, publicApi, type PublicStoreItem } from "@/lib/public-api";
import { publicPageMetadata } from "@/lib/seo";

export const revalidate = 60;

type Params = { item: string };

async function load(uuid: string): Promise<PublicStoreItem> {
  try {
    return await publicApi.storeItem(uuid);
  } catch (error) {
    // An unlisted teacher, a product off sale, or no such uuid — one 404 for all.
    if (error instanceof NotFoundError) notFound();
    throw error;
  }
}

export async function generateMetadata({ params }: { params: Promise<Params> }): Promise<Metadata> {
  const { item: uuid } = await params;
  const item = await load(uuid);

  return publicPageMetadata({
    path: `/store/${item.uuid}`,
    title: item.title,
    description: item.excerpt ?? `${item.kind_label} — ${item.teacher?.name ?? "المتجر"}`,
    image: item.cover_url ?? "/marketplace/banner-courses.webp",
  });
}

/** One product: cover, the full description, and the buy box. */
export default async function StoreItemPage({ params }: { params: Promise<Params> }) {
  const { item: uuid } = await params;
  const item = await load(uuid);
  const KindIcon = item.kind === "digital" ? DocumentIcon : BookIcon;

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
      <nav aria-label="مسار التنقل" className="mb-8">
        <ol className="flex flex-wrap items-center gap-2 text-sm font-semibold">
          <li>
            <Link
              href="/store"
              className="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface-raised px-4 py-1.5 text-ink transition hover:border-primary hover:text-primary-ink motion-reduce:transition-none"
            >
              <StoreIcon className="h-4 w-4" aria-hidden="true" />
              المتجر
            </Link>
          </li>
          {item.subject && (
            <>
              <li aria-hidden="true" className="text-ink-muted">
                <ChevronStartIcon className="h-4 w-4" />
              </li>
              <li>
                <Link
                  href={`/store?subject=${encodeURIComponent(item.subject.slug)}`}
                  className="inline-flex rounded-full bg-primary-soft px-4 py-1.5 text-primary-ink transition hover:bg-primary hover:text-white motion-reduce:transition-none"
                >
                  {item.subject.name}
                </Link>
              </li>
            </>
          )}
        </ol>
      </nav>

      <div className="grid gap-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-14">
        {/* The cover is the hero object: a burgundy stage carrying the
            wordmark's square dots, the book standing on it. */}
        <div className="lg:sticky lg:top-28 lg:self-start">
          <div className="bg-squares group relative isolate flex aspect-[3/4] w-full items-center justify-center overflow-hidden rounded-3xl bg-primary p-8 shadow-xl shadow-primary/20 sm:p-12">
            <KindIcon
              aria-hidden="true"
              className="pointer-events-none absolute -bottom-10 -end-10 -z-10 h-56 w-56 text-white/10"
            />
            {item.cover_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
              <img
                src={item.cover_url}
                alt={`غلاف ${item.title}`}
                className="max-h-full w-auto max-w-full rounded-xl object-cover shadow-2xl shadow-primary-ink/40 transition duration-500 ease-out group-hover:-translate-y-1 group-hover:-rotate-2 motion-reduce:transition-none motion-reduce:group-hover:translate-y-0 motion-reduce:group-hover:rotate-0"
              />
            ) : (
              <span className="grid h-32 w-32 place-items-center rounded-3xl bg-accent text-accent-foreground shadow-xl shadow-primary-ink/30 ring-4 ring-primary transition duration-300 ease-out group-hover:-translate-y-1.5 group-hover:rotate-3 motion-reduce:transition-none motion-reduce:group-hover:translate-y-0 motion-reduce:group-hover:rotate-0">
                <KindIcon className="h-16 w-16" aria-hidden />
              </span>
            )}
          </div>
        </div>

        <div className="space-y-8">
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-primary px-3.5 py-1 text-xs font-bold text-white">
                <KindIcon className="h-4 w-4" aria-hidden />
                {item.kind_label}
              </span>
              {item.subject && <Badge tone="info">{item.subject.name}</Badge>}
            </div>

            <h1 className="text-balance text-4xl font-extrabold leading-tight text-ink sm:text-5xl">{item.title}</h1>

            {item.teacher && (
              <p className="text-base text-ink-muted">
                {"من "}
                <Link
                  href={`/teachers/${item.teacher.slug ?? item.teacher.uuid}`}
                  className="font-bold text-primary-ink underline decoration-primary/30 underline-offset-4 transition hover:decoration-primary motion-reduce:transition-none"
                >
                  {item.teacher.name}
                </Link>
              </p>
            )}

            {item.excerpt && <p className="text-lg leading-relaxed text-ink-muted">{item.excerpt}</p>}
          </div>

          <div className="space-y-5 rounded-3xl border border-primary/20 bg-surface-raised p-6 shadow-lg shadow-primary/10 sm:p-7">
            <div className="flex flex-wrap items-baseline gap-3">
              <span className="text-4xl font-extrabold text-ink">{formatMinorMoney(item.price_minor, item.currency)}</span>
              {item.kind === "physical" && item.shipping_fee_minor !== null && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-primary-ink">
                  <TruckIcon aria-hidden />
                  {item.shipping_fee_minor > 0
                    ? `+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`
                    : "شحن مجاني"}
                </span>
              )}
            </div>

            <p className="flex items-start gap-3 text-sm leading-relaxed text-ink-muted">
              <span
                aria-hidden="true"
                className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink"
              >
                {item.kind === "digital" ? <DocumentIcon className="h-5 w-5" /> : <TruckIcon className="h-5 w-5" />}
              </span>
              <span className="pt-0.5">
                {item.kind === "digital"
                  ? "نسخة رقمية تُفتح من «مشترياتي» فور اعتماد دفعتك. الاسترداد متاح خلال ٤٨ ساعة ما لم يُفتح الملف."
                  : "نسخة مطبوعة تُجهَّز وتُشحن بعد اعتماد دفعتك، وتصلك رسالة مع كل خطوة."}
              </span>
            </p>

            <StoreBuyPanel item={item} />
          </div>

          {item.course && (
            <p className="group flex items-center gap-3 rounded-2xl border border-line bg-surface-raised p-4 text-sm text-ink-muted transition hover:border-primary/40 motion-reduce:transition-none">
              <span
                aria-hidden="true"
                className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition group-hover:bg-primary group-hover:text-white motion-reduce:transition-none"
              >
                <CoursesIcon className="h-5 w-5" />
              </span>
              <span>
                {"مرتبط بكورس "}
                <Link href={`/courses/${item.course.slug ?? item.course.uuid}`} className="font-bold text-primary-ink hover:underline">
                  {item.course.title}
                </Link>
              </span>
            </p>
          )}

          {item.description_html && (
            <section aria-label="وصف المنتج" className="border-t border-line pt-8">
              <h2 className="mb-4 text-2xl font-extrabold text-ink">عن هذا المنتج</h2>
              <ArticleBody html={item.description_html} />
            </section>
          )}
        </div>
      </div>
    </div>
  );
}
