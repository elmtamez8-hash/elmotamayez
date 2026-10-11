import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArticleBody } from "@/components/blog/ArticleBody";
import { BookIcon, ChevronEndIcon, ChevronStartIcon, CoursesIcon, DocumentIcon, RefundIcon, StoreIcon, TruckIcon, UserIcon } from "@/components/icons";
import { subjectIcon } from "@/components/marketplace/subject-icon";
import { StoreBuyPanel } from "@/components/store/StoreBuyPanel";
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

/** What the buyer gets, in the words the product already promised — nothing new is claimed here. */
const FACTS = {
  digital: [
    { Icon: DocumentIcon, text: "نسخة رقمية تُفتح من «مشترياتي» فور اعتماد دفعتك." },
    { Icon: RefundIcon, text: "الاسترداد متاح خلال ٤٨ ساعة ما لم يُفتح الملف." },
  ],
  physical: [
    { Icon: BookIcon, text: "نسخة مطبوعة تُجهَّز وتُشحن بعد اعتماد دفعتك." },
    { Icon: TruckIcon, text: "وتصلك رسالة مع كل خطوة." },
  ],
} as const;

/** One product: cover, the full description, and the buy box. */
export default async function StoreItemPage({ params }: { params: Promise<Params> }) {
  const { item: uuid } = await params;
  const item = await load(uuid);
  const KindIcon = item.kind === "digital" ? DocumentIcon : BookIcon;
  const Mark = item.subject ? subjectIcon(item.subject) : null;

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

      {/*
        The bold product page (owner request 2026-10-11): a burgundy stage with
        the cover standing in it beside the title, the buy box beside the content
        (sticky on a wide screen, straight after the stage on a phone), then the
        facts, the description and the course it belongs to.
      */}
      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-10">
        <header className="bg-squares relative isolate overflow-hidden rounded-3xl bg-primary p-6 text-white shadow-xl shadow-primary/20 sm:p-8 lg:col-start-1">
          <KindIcon
            aria-hidden="true"
            className="pointer-events-none absolute -bottom-12 -end-12 -z-10 h-64 w-64 text-white/10"
          />
          <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:gap-8">
            <div className="mx-auto w-40 shrink-0 sm:mx-0 sm:w-48">
              {item.cover_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
                <img
                  src={item.cover_url}
                  alt={`غلاف ${item.title}`}
                  className="aspect-[3/4] w-full -rotate-2 rounded-xl object-cover shadow-2xl shadow-primary-ink/50 ring-4 ring-white/15 transition duration-500 ease-out hover:rotate-0 motion-reduce:transition-none"
                />
              ) : (
                <span className="grid aspect-[3/4] w-full place-items-center rounded-xl bg-accent text-accent-foreground shadow-2xl shadow-primary-ink/40">
                  <KindIcon className="h-16 w-16" aria-hidden />
                </span>
              )}
            </div>

            <div className="min-w-0 space-y-4">
              <div className="flex flex-wrap gap-2">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-white px-3.5 py-1 text-xs font-extrabold text-primary-ink">
                  <KindIcon className="h-4 w-4" aria-hidden />
                  {item.kind_label}
                </span>
                {item.subject && Mark && (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3.5 py-1 text-xs font-bold text-white">
                    <Mark className="h-4 w-4" aria-hidden />
                    {item.subject.name}
                  </span>
                )}
                {!item.is_available && (
                  <span className="rounded-full bg-danger px-3.5 py-1 text-xs font-bold text-white">نفدت النسخ</span>
                )}
              </div>

              <h1 className="text-balance text-3xl font-extrabold leading-tight sm:text-4xl">{item.title}</h1>

              {item.excerpt && <p className="text-base leading-relaxed text-white/85">{item.excerpt}</p>}

              {item.teacher?.name && (
                <Link
                  href={`/teachers/${item.teacher.slug ?? item.teacher.uuid}`}
                  className="inline-flex items-center gap-2 rounded-full bg-white/10 py-1.5 pe-4 ps-1.5 text-sm font-bold text-white transition hover:bg-white/20 motion-reduce:transition-none"
                >
                  <span aria-hidden="true" className="grid h-7 w-7 place-items-center rounded-full bg-white text-primary-ink">
                    <UserIcon className="h-4 w-4" />
                  </span>
                  {`من إعداد ${item.teacher.name}`}
                </Link>
              )}
            </div>
          </div>
        </header>

        <aside className="lg:sticky lg:top-28 lg:col-start-2 lg:row-span-3 lg:row-start-1 lg:self-start">
          <div className="space-y-5 rounded-3xl border border-primary/20 bg-surface-raised p-6 shadow-lg shadow-primary/10">
            <div>
              <p className="text-sm font-bold text-ink-muted">السعر</p>
              <p className="mt-1 text-4xl font-extrabold text-ink">{formatMinorMoney(item.price_minor, item.currency)}</p>
              {item.kind === "physical" && item.shipping_fee_minor !== null && (
                <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-primary-ink">
                  <TruckIcon className="h-4 w-4" aria-hidden />
                  {item.shipping_fee_minor > 0
                    ? `+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`
                    : "شحن مجاني"}
                </p>
              )}
            </div>

            <StoreBuyPanel item={item} />

            <ul className="space-y-3 border-t border-line pt-5 text-sm leading-relaxed text-ink-muted">
              {FACTS[item.kind].map(({ Icon, text }) => (
                <li key={text} className="flex items-start gap-3">
                  <span aria-hidden="true" className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary-ink">
                    <Icon className="h-4 w-4" />
                  </span>
                  <span className="pt-1">{text}</span>
                </li>
              ))}
            </ul>
          </div>
        </aside>

        <div className="space-y-8 lg:col-start-1">
          {item.course && (
            <Link
              href={`/courses/${item.course.slug ?? item.course.uuid}`}
              className="group flex items-center gap-4 rounded-2xl border border-line bg-surface-raised p-4 transition hover:border-primary/40 hover:shadow-md hover:shadow-primary/10 motion-reduce:transition-none"
            >
              <span
                aria-hidden="true"
                className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition group-hover:bg-primary group-hover:text-white motion-reduce:transition-none"
              >
                <CoursesIcon className="h-6 w-6" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-xs font-bold text-ink-muted">مرتبط بكورس</span>
                <span className="block truncate font-extrabold text-ink group-hover:text-primary-ink">{item.course.title}</span>
              </span>
              <ChevronEndIcon className="h-5 w-5 shrink-0 text-ink-muted transition group-hover:-translate-x-1 group-hover:text-primary-ink motion-reduce:transition-none" aria-hidden />
            </Link>
          )}

          {item.description_html && (
            <section aria-label="وصف المنتج" className="rounded-3xl border border-line bg-surface-raised p-6 sm:p-8">
              <h2 className="mb-4 text-2xl font-extrabold text-ink">عن هذا المنتج</h2>
              <ArticleBody html={item.description_html} />
            </section>
          )}
        </div>
      </div>
    </div>
  );
}
