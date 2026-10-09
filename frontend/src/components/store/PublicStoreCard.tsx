import Link from "next/link";
import { BookIcon, ChevronStartIcon, DocumentIcon } from "@/components/icons";
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
    <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 active:translate-y-0 active:duration-100 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
      {/* The cover stands on a soft stage like a book on a shelf, and tilts a
          little toward the reader on hover. */}
      <div className="relative isolate flex aspect-[4/3] items-center justify-center overflow-hidden bg-primary-soft">
        <KindIcon
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-6 -start-6 -z-10 h-32 w-32 text-primary-ink/10"
        />

        {item.cover_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
          <img
            src={item.cover_url}
            alt=""
            className="h-[82%] w-auto rounded-lg object-cover shadow-xl shadow-primary/20 transition duration-300 ease-out group-hover:-translate-y-1 group-hover:-rotate-2 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:translate-y-0 motion-reduce:group-hover:rotate-0 motion-reduce:group-hover:scale-100"
          />
        ) : (
          <span
            aria-hidden="true"
            className="grid h-20 w-20 place-items-center rounded-3xl bg-surface-raised text-primary-ink shadow-lg shadow-primary/10 transition duration-300 ease-out group-hover:-rotate-3 group-hover:bg-primary group-hover:text-white motion-reduce:transition-none motion-reduce:group-hover:rotate-0"
          >
            <KindIcon className="h-10 w-10" />
          </span>
        )}

        <span className="absolute top-3 end-3 rounded-full bg-surface-raised/95 px-3 py-1 text-xs font-extrabold text-primary-ink shadow-sm">
          {item.kind_label}
        </span>

        {!item.is_available && (
          <span className="absolute top-3 start-3 rounded-full bg-danger px-3 py-1 text-xs font-bold text-white shadow-sm">
            نفدت النسخ
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col gap-2 p-5">
        {item.subject && <p className="text-xs font-bold text-primary-ink">{item.subject.name}</p>}

        <h3 className="text-lg font-extrabold leading-snug text-ink">
          <Link
            href={`/store/${item.uuid}`}
            className="after:absolute after:inset-0 after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {item.title}
          </Link>
        </h3>

        {item.excerpt && <p className="line-clamp-2 text-sm leading-relaxed text-ink-muted">{item.excerpt}</p>}

        {item.teacher && (
          <Link
            href={`/teachers/${item.teacher.slug ?? item.teacher.uuid}`}
            className="relative z-10 w-fit text-sm font-semibold text-ink-muted hover:text-primary-ink hover:underline"
          >
            {item.teacher.name}
          </Link>
        )}

        <div className="mt-auto flex items-end justify-between gap-3 border-t border-line pt-4">
          <div className="flex flex-wrap items-baseline gap-x-2">
            <span className="text-xl font-extrabold text-ink">{formatMinorMoney(item.price_minor, item.currency)}</span>
            {item.kind === "physical" && item.shipping_fee_minor !== null && item.shipping_fee_minor > 0 && (
              <span className="text-xs text-ink-muted">{`+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`}</span>
            )}
          </div>

          <span
            aria-hidden="true"
            className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition duration-300 ease-out group-hover:bg-primary group-hover:text-white motion-reduce:transition-none"
          >
            <ChevronStartIcon className="h-4 w-4" />
          </span>
        </div>
      </div>
    </article>
  );
}
