import type { Metadata } from "next";
import Link from "next/link";
import { publicPageMetadata } from "@/lib/seo";
import { STORE_FILTER_KEYS, publicApi, type Paginated, type PublicStoreItem, type StoreFacets } from "@/lib/public-api";
import { PublicStoreCard } from "@/components/store/PublicStoreCard";
import { StoreFilters } from "@/components/store/StoreFilters";
import { Pagination } from "@/components/ui/Pagination";
import { PageBanner } from "@/components/ui/PageBanner";
import { StoreIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { counted } from "@/lib/labels";

export const revalidate = 60;

export function generateMetadata(): Promise<Metadata> {
  return publicPageMetadata({
    path: "/store",
    title: "المتجر",
    description: "كتب ومذكّرات المدرّسين — نسخ رقمية تصلك فور اعتماد الدفع، ونسخ مطبوعة تُشحن إلى بابك.",
    image: "/marketplace/banner-courses.webp",
  });
}

type SearchParams = Record<string, string | string[] | undefined>;

const QUERY_KEYS = [...STORE_FILTER_KEYS, "sort", "page"] as const;

function toQuery(params: SearchParams): Record<string, string | undefined> {
  return Object.fromEntries(
    QUERY_KEYS.map((key) => [key, typeof params[key] === "string" ? (params[key] as string) : undefined]),
  );
}

/**
 * The public store (2026-10-01): every listed teacher's books and notes, for a
 * guest or anybody signed in. The buyer's own purchases live on `/purchases`.
 */
export default async function StorePage({ searchParams }: { searchParams: Promise<SearchParams> }) {
  const params = await searchParams;

  let items: Paginated<PublicStoreItem>;
  let facets: StoreFacets;

  try {
    [items, facets] = await Promise.all([publicApi.storeItems(toQuery(params)), publicApi.storeFacets()]);
  } catch {
    return (
      <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6">
        <ErrorState />
      </div>
    );
  }

  const hasFilters = STORE_FILTER_KEYS.some((key) => typeof params[key] === "string" && params[key] !== "");

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <PageBanner
        icon={StoreIcon}
        image="/marketplace/banner-courses.webp"
        title="المتجر"
        description="كتب ومذكّرات مدرّسيك — نسخة رقمية تصلك فور اعتماد الدفع، أو نسخة مطبوعة تُشحن إلى بابك."
      >
        <p className="mt-5 inline-flex items-center rounded-full bg-white/15 px-4 py-1.5 text-sm font-semibold text-white backdrop-blur-sm">
          {counted(items.meta.total, {
            one: "منتج متاح",
            two: "منتجان متاحان",
            few: "منتجات متاحة",
            many: "منتجاً متاحاً",
            other: "منتج متاح",
          })}
        </p>
      </PageBanner>

      <StoreFilters facets={facets} />

      <section aria-label="منتجات المتجر">
        {items.data.length === 0 ? (
          <EmptyState
            title="لا توجد منتجات مطابقة"
            description={
              hasFilters
                ? "جرّب مدرّساً آخر أو مادة أخرى، أو أزِل الفلاتر."
                : "لم يعرض المدرّسون منتجات بعد. تصفّح الكورسات في الأثناء."
            }
            action={
              <Link
                href={hasFilters ? "/store" : "/courses"}
                className="rounded-full bg-primary px-6 py-3 text-sm font-bold text-white shadow-lg shadow-primary/20 transition duration-300 ease-out hover:-translate-y-0.5 hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0"
              >
                {hasFilters ? "إزالة كل الفلاتر" : "تصفّح الكورسات"}
              </Link>
            }
          />
        ) : (
          <>
            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {items.data.map((item, index) => (
                <div
                  key={item.uuid}
                  className="animate-float-in grid"
                  style={{ animationDelay: `${Math.min(index, 7) * 45}ms` }}
                >
                  <PublicStoreCard item={item} />
                </div>
              ))}
            </div>

            <Pagination basePath="/store" currentPage={items.meta.current_page} lastPage={items.meta.last_page} searchParams={params} />
          </>
        )}
      </section>
    </div>
  );
}
