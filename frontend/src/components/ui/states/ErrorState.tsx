"use client";

import { AlertIcon, RefreshIcon } from "@/components/icons";

/**
 * Shown when a public page cannot reach the API (FR-079). It offers a retry
 * because the common cause is transient, and a dead end with no way forward is
 * worse than the error itself.
 */
export function ErrorState({
  title = "تعذّر تحميل البيانات",
  description = "حدث خطأ أثناء جلب المحتوى. تحقّق من اتصالك ثم أعد المحاولة.",
  onRetry,
}: {
  title?: string;
  description?: string;
  onRetry?: () => void;
}) {
  return (
    /*
      ⚠️ Start-aligned, the icon BESIDE the text — not the centred icon-over-title
      stack the owner read as machine-made (2026-10-09, see `EmptyState`). It sits
      inside narrow dashboard cards too, so it wraps by its container.
    */
    <div className="@container" role="alert">
      <div className="flex flex-col gap-4 rounded-3xl border border-danger/30 bg-danger/5 p-5 @md:flex-row @md:items-center">
        <span
          aria-hidden="true"
          className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-danger text-white"
        >
          <AlertIcon className="h-6 w-6" />
        </span>
        <div className="min-w-0 flex-1 text-start">
          <h2 className="text-lg font-extrabold text-ink">{title}</h2>
          <p className="mt-1 text-sm leading-relaxed text-ink-muted">{description}</p>
        </div>
        <button
          type="button"
          onClick={() => (onRetry ? onRetry() : window.location.reload())}
          className="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-full bg-primary px-5 py-2.5 text-sm font-bold text-white shadow-md shadow-primary/20 transition hover:-translate-y-0.5 hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:hover:translate-y-0 @md:self-center"
        >
          <RefreshIcon />
          إعادة المحاولة
        </button>
      </div>
    </div>
  );
}
