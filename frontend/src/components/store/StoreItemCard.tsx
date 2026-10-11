"use client";

import { BookIcon, DocumentIcon, TruckIcon } from "@/components/icons";
import { formatMinorMoney } from "@/lib/labels";
import type { StoreItem } from "@/lib/store";

/**
 * One product, as a buyer sees it.
 *
 * In the public store card's language (owner request 2026-10-11): the cover on
 * the burgundy stage, the facts as chips, one accent button — so the shop seen
 * from inside the account reads as the same shop seen from outside.
 *
 * ⚠️ `stock === null` MEANS «CANNOT RUN OUT», AND THE CARD MUST SAY NOTHING AT
 * ALL IN THAT CASE. Rendering `stock ?? 0` — or «٠ متبقّية» — tells every buyer
 * that a file is sold out, which is the exact reading `ClaimStock` branches on
 * `kind` to avoid on the server.
 *
 * ⚠️ AND NO COMMISSION AND NO TEACHER SHARE APPEAR HERE. The buyer sees the
 * price they pay; the split is the teacher's business and the platform's, and a
 * number on both screens is one subtraction away from the other side's rate.
 */
export function StoreItemCard({
  item,
  onBuy,
}: {
  item: StoreItem;
  onBuy: (item: StoreItem) => void;
}) {
  const soldOut = item.stock !== null && item.stock <= 0;
  const KindIcon = item.kind === "digital" ? DocumentIcon : BookIcon;

  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
      <div className="bg-squares relative isolate flex aspect-[4/3] items-end justify-center overflow-hidden bg-primary px-8 pt-8">
        <KindIcon
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-8 -start-8 -z-10 h-36 w-36 text-white/10"
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
            className="mb-10 grid h-20 w-20 place-items-center rounded-3xl bg-accent text-accent-foreground shadow-xl shadow-primary-ink/30"
          >
            <KindIcon className="h-10 w-10" />
          </span>
        )}

        <span className="absolute top-3 end-3 inline-flex items-center gap-1 rounded-full bg-surface-raised/95 px-3 py-1 text-xs font-extrabold text-primary-ink shadow-md">
          <KindIcon className="h-3.5 w-3.5" aria-hidden />
          {item.kind_label}
        </span>

        {/* Only a stocked item says anything about stock, and only when it is low
            enough to matter or gone. A file says nothing. */}
        {soldOut ? (
          <span className="absolute top-3 start-3 rounded-full bg-danger px-3 py-1 text-xs font-bold text-white shadow-md">
            نفدت النسخ
          </span>
        ) : item.stock !== null && item.stock <= 5 ? (
          <span className="absolute top-3 start-3 rounded-full bg-accent px-3 py-1 text-xs font-bold text-accent-foreground shadow-md">
            {`بقي ${item.stock}`}
          </span>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="line-clamp-2 text-lg font-extrabold leading-snug text-ink">
          {item.title}
        </h3>

        {/* The excerpt, or the start of the description when none was written —
            the form promises «اتركه فارغاً ليظهر أول الوصف». */}
        {(item.excerpt || item.description) && (
          <p className="mt-1.5 line-clamp-3 text-sm leading-relaxed text-ink-muted">
            {item.excerpt || item.description}
          </p>
        )}

        <div className="mt-auto pt-5">
          <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span className="text-2xl font-extrabold text-ink">
              {formatMinorMoney(item.price_minor, item.currency)}
            </span>
            {item.shipping_fee_minor !== null &&
              item.shipping_fee_minor > 0 && (
                <span className="inline-flex items-center gap-1 text-xs font-semibold text-ink-muted">
                  <TruckIcon className="h-3.5 w-3.5" aria-hidden />
                  {`+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`}
                </span>
              )}
          </div>

          <button
            type="button"
            disabled={soldOut}
            onClick={() => onBuy(item)}
            className="w-full rounded-full bg-accent px-4 py-2.5 text-sm font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:bg-line disabled:text-ink-muted motion-reduce:transition-none"
          >
            {soldOut ? "غير متاح" : "اشترِ"}
          </button>
        </div>
      </div>
    </article>
  );
}
