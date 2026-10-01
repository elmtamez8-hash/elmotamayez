import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArticleBody } from "@/components/blog/ArticleBody";
import { BookIcon, DocumentIcon, TruckIcon } from "@/components/icons";
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
      <nav aria-label="مسار التنقل" className="mb-6 text-sm text-ink-muted">
        <Link href="/store" className="hover:text-primary-ink">
          المتجر
        </Link>
        {item.subject && (
          <>
            <span className="mx-2" aria-hidden>
              ‹
            </span>
            <Link href={`/store?subject=${encodeURIComponent(item.subject.slug)}`} className="hover:text-primary-ink">
              {item.subject.name}
            </Link>
          </>
        )}
      </nav>

      <div className="grid gap-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <div className="flex items-start justify-center">
          <div className="flex aspect-[3/4] w-full max-w-sm items-center justify-center overflow-hidden rounded-3xl border border-line bg-primary-soft shadow-sm">
            {item.cover_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
              <img src={item.cover_url} alt={`غلاف ${item.title}`} className="h-full w-full object-cover" />
            ) : (
              <KindIcon className="h-20 w-20 text-primary-ink/60" aria-hidden />
            )}
          </div>
        </div>

        <div className="space-y-6">
          <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
              <Badge tone="neutral">{item.kind_label}</Badge>
              {item.subject && <Badge tone="info">{item.subject.name}</Badge>}
            </div>

            <h1 className="text-2xl font-bold leading-snug text-ink sm:text-3xl">{item.title}</h1>

            {item.teacher && (
              <p className="text-sm text-ink-muted">
                {"من "}
                <Link
                  href={`/teachers/${item.teacher.slug ?? item.teacher.uuid}`}
                  className="font-semibold text-primary-ink hover:underline"
                >
                  {item.teacher.name}
                </Link>
              </p>
            )}

            {item.excerpt && <p className="text-base text-ink-muted">{item.excerpt}</p>}
          </div>

          <div className="space-y-4 rounded-2xl border border-line bg-surface-raised p-5">
            <div className="flex flex-wrap items-baseline gap-3">
              <span className="text-3xl font-bold text-ink">{formatMinorMoney(item.price_minor, item.currency)}</span>
              {item.kind === "physical" && item.shipping_fee_minor !== null && (
                <span className="inline-flex items-center gap-1 text-sm text-ink-muted">
                  <TruckIcon aria-hidden />
                  {item.shipping_fee_minor > 0
                    ? `+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`
                    : "شحن مجاني"}
                </span>
              )}
            </div>

            <p className="text-sm text-ink-muted">
              {item.kind === "digital"
                ? "نسخة رقمية تُفتح من «مشترياتي» فور اعتماد دفعتك. الاسترداد متاح خلال ٤٨ ساعة ما لم يُفتح الملف."
                : "نسخة مطبوعة تُجهَّز وتُشحن بعد اعتماد دفعتك، وتصلك رسالة مع كل خطوة."}
            </p>

            <StoreBuyPanel item={item} />
          </div>

          {item.course && (
            <p className="text-sm text-ink-muted">
              {"مرتبط بكورس "}
              <Link href={`/courses/${item.course.slug ?? item.course.uuid}`} className="font-semibold text-primary-ink hover:underline">
                {item.course.title}
              </Link>
            </p>
          )}

          {item.description_html && (
            <section aria-label="وصف المنتج" className="border-t border-line pt-6">
              <h2 className="mb-3 text-lg font-bold text-ink">عن هذا المنتج</h2>
              <ArticleBody html={item.description_html} />
            </section>
          )}
        </div>
      </div>
    </div>
  );
}
