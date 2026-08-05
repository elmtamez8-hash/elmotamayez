"use client";

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
    <div
      className="flex flex-col items-center justify-center rounded-2xl border border-line bg-surface-raised px-6 py-16 text-center"
      role="alert"
    >
      <svg
        className="mb-4 h-12 w-12 text-danger-ink"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.5}
        aria-hidden="true"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
        />
      </svg>
      <h2 className="mb-2 text-lg font-semibold text-ink">{title}</h2>
      <p className="mb-6 max-w-md text-sm leading-relaxed text-ink-muted">
        {description}
      </p>
      <button
        type="button"
        onClick={() => (onRetry ? onRetry() : window.location.reload())}
        className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        إعادة المحاولة
      </button>
    </div>
  );
}
