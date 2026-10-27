import Link from "next/link";
import { BookIcon, ChevronEndIcon, DocumentIcon, TruckIcon, UserIcon } from "@/components/icons";
import { subjectIcon } from "@/components/marketplace/subject-icon";
import { formatMinorMoney } from "@/lib/labels";
import type { PublicStoreItem } from "@/lib/public-api";

/**
 * A product on the public store — the course card's shape (whole card one link,
 * the teacher's byline a separate destination above it on the z-axis).
 *
 * The bold card (owner request 2026-10-11), in the COURSE card's language: a
 * brand-colour stage with the cover standing on it, the facts as chips, and one
 * accent button — so the shop, the courses and the teachers read as one product.
 *
 * The price IS on this card, unlike a course card's: this is a shop, and the
 * product's price is not the course price spec 006 took off the browse surfaces.
 */
export function PublicStoreCard({ item }: { item: PublicStoreItem }) {
  const KindIcon = item.kind === "digital" ? DocumentIcon : BookIcon;
  const Mark = item.subject ? subjectIcon(item.subject) : null;
  const shipping = item.kind === "physical" ? item.shipping_fee_minor : null;

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 active:translate-y-0 active:duration-100 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
      {/* The cover stands on the burgundy stage like a book on a shelf, and
          lifts a little toward the reader on hover. */}
      <div className="bg-squares relative isolate flex aspect-[4/3] items-end justify-center overflow-hidden bg-primary px-8 pt-8">
        <KindIcon
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-8 -start-8 -z-10 h-36 w-36 text-white opacity-10"
        />

        {item.cover_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
          <img
            src={item.cover_url}
            alt=""
            loading="lazy"
            className="h-[88%] w-auto max-w-[70%] translate-y-3 rounded-t-lg object-cover shadow-2xl shadow-primary-ink/50 transition duration-300 ease-out group-hover:translate-y-1 group-hover:-rotate-2 motion-reduce:transition-none motion-reduce:group-hover:translate-y-3 motion-reduce:group-hover:rotate-0"
          />
        ) : (
          <span
            aria-hidden="true"
            className="mb-10 grid h-20 w-20 place-items-center rounded-3xl bg-accent text-accent-foreground shadow-xl shadow-primary-ink/30 transition duration-300 ease-out group-hover:-rotate-3 motion-reduce:transition-none motion-reduce:group-hover:rotate-0"
          >
            <KindIcon className="h-10 w-10" />
          </span>
        )}

        <span className="absolute top-3 end-3 inline-flex items-center gap-1 rounded-full bg-surface-raised/95 px-3 py-1 text-xs font-extrabold text-primary-ink shadow-md">
          <KindIcon className="h-3.5 w-3.5" aria-hidden />
          {item.kind_label}
        </span>

        {!item.is_available && (
          <span className="absolute top-3 start-3 rounded-full bg-danger px-3 py-1 text-xs font-bold text-white shadow-md">
            نفدت النسخ
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col p-5">
        {item.subject && Mark && (
          <span className="mb-2.5 inline-flex w-fit items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary-ink">
            <Mark className="h-3.5 w-3.5" />
            {item.subject.name}
          </span>
        )}

        {/* ⚠️ THE WHOLE CARD, IN ONE CLICK: the `after:` overlay stretches this
            link across the article; the teacher byline sits above it. */}
        <h3 className="line-clamp-2 text-lg font-extrabold leading-snug text-ink transition-colors group-hover:text-primary-ink motion-reduce:transition-none">
          <Link
            href={`/store/${item.uuid}`}
            className="after:absolute after:inset-0 after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {item.title}
          </Link>
        </h3>

        {item.excerpt && <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-ink-muted">{item.excerpt}</p>}

        {item.teacher?.name && (
          <Link
            href={`/teachers/${item.teacher.slug ?? item.teacher.uuid}`}
            className="relative z-10 mt-3 inline-flex w-fit items-center gap-1.5 text-sm font-bold text-ink-muted transition-colors hover:text-primary-ink"
          >
            <UserIcon className="h-4 w-4" aria-hidden />
            {item.teacher.name}
          </Link>
        )}

        <div className="mt-auto pt-5">
          <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span className="text-2xl font-extrabold text-ink">{formatMinorMoney(item.price_minor, item.currency)}</span>
            {shipping !== null && (
              <span className="inline-flex items-center gap-1 text-xs font-semibold text-ink-muted">
                <TruckIcon className="h-3.5 w-3.5" aria-hidden />
                {shipping > 0 ? `شحن ${formatMinorMoney(shipping, item.currency)}` : "شحن مجاني"}
              </span>
            )}
          </div>
          <span
            aria-hidden="true"
            className="flex items-center justify-center gap-2 rounded-full bg-accent px-4 py-2.5 text-sm font-semibold text-accent-foreground transition duration-200 ease-out group-hover:brightness-105 motion-reduce:transition-none"
          >
            عرض المنتج
            <ChevronEndIcon className="h-4 w-4 transition-transform duration-300 ease-out group-hover:-translate-x-1 motion-reduce:transition-none motion-reduce:group-hover:translate-x-0" />
          </span>
        </div>
      </div>
    </article>
  );
}
