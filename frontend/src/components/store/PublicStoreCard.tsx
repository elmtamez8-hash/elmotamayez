import Link from "next/link";
import { BookIcon, DocumentIcon } from "@/components/icons";
import { formatMinorMoney } from "@/lib/labels";
import type { PublicStoreItem } from "@/lib/public-api";

/**
 * A product on the public store — the course card's shape (whole card one link,
 * the teacher's byline a separate destination above it on the z-axis).
 *
 * The price IS on this card, unlike a course card's: this is a shop, and the
 * product's price is not the course price spec 006 took off the browse surfaces.
 */
export function PublicStoreCard({ item }: { item: PublicStoreItem }) {
  const KindIcon = item.kind === "digital" ? DocumentIcon : BookIcon;

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised transition duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg active:translate-y-0 active:duration-100">
      <div className="relative flex aspect-[4/3] items-center justify-center bg-primary-soft">
        {item.cover_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
          <img
            src={item.cover_url}
            alt=""
            className="h-[85%] w-auto rounded-lg object-cover shadow-md transition duration-300 group-hover:scale-[1.03]"
          />
        ) : (
          <KindIcon className="h-14 w-14 text-primary-ink/60" aria-hidden />
        )}

        <span className="absolute top-3 end-3 rounded-full bg-surface-raised/95 px-2.5 py-1 text-xs font-bold text-primary-ink shadow-sm">
          {item.kind_label}
        </span>

        {!item.is_available && (
          <span className="absolute top-3 start-3 rounded-full bg-danger px-2.5 py-1 text-xs font-bold text-white">
            نفدت النسخ
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col gap-2 p-5">
        {item.subject && <p className="text-xs font-semibold text-primary-ink">{item.subject.name}</p>}

        <h3 className="text-base font-bold leading-snug text-ink">
          <Link
            href={`/store/${item.uuid}`}
            className="after:absolute after:inset-0 after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {item.title}
          </Link>
        </h3>

        {item.excerpt && <p className="line-clamp-2 text-sm text-ink-muted">{item.excerpt}</p>}

        {item.teacher && (
          <Link
            href={`/teachers/${item.teacher.slug ?? item.teacher.uuid}`}
            className="relative z-10 w-fit text-sm text-ink-muted hover:text-primary-ink"
          >
            {item.teacher.name}
          </Link>
        )}

        <div className="mt-auto flex items-baseline gap-2 pt-2">
          <span className="text-lg font-bold text-ink">{formatMinorMoney(item.price_minor, item.currency)}</span>
          {item.kind === "physical" && item.shipping_fee_minor !== null && item.shipping_fee_minor > 0 && (
            <span className="text-xs text-ink-muted">{`+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`}</span>
          )}
        </div>
      </div>
    </article>
  );
}
