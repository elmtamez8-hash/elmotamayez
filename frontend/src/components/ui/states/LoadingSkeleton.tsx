/**
 * Skeletons deliberately mirror the final card dimensions so swapping real data in
 * causes no layout shift (FR-077).
 */

function Shimmer({ className = "" }: { className?: string }) {
  return (
    <div className={`animate-pulse rounded-lg bg-line ${className}`} />
  );
}

export function TeacherCardSkeleton() {
  return (
    <div className="rounded-2xl border border-line bg-surface-raised p-5">
      <div className="mb-4 flex items-center gap-4">
        <Shimmer className="h-16 w-16 rounded-full" />
        <div className="flex-1 space-y-2">
          <Shimmer className="h-4 w-2/3" />
          <Shimmer className="h-3 w-1/2" />
        </div>
      </div>
      <Shimmer className="mb-3 h-3 w-full" />
      <Shimmer className="mb-5 h-3 w-4/5" />
      <div className="flex gap-2">
        <Shimmer className="h-10 flex-1" />
        <Shimmer className="h-10 flex-1" />
      </div>
    </div>
  );
}

export function CourseCardSkeleton() {
  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-surface-raised">
      <Shimmer className="h-40 w-full rounded-none" />
      <div className="space-y-3 p-5">
        <Shimmer className="h-4 w-3/4" />
        <Shimmer className="h-3 w-1/2" />
        <Shimmer className="h-3 w-2/3" />
      </div>
    </div>
  );
}

/**
 * Rows, for the panel's lists and tables. The card grid above is the marketplace
 * shape and reads as wrong above a table.
 */
export function RowsSkeleton({ count = 5 }: { count?: number }) {
  return (
    <div
      className="space-y-3 rounded-2xl border border-line bg-surface-raised p-4"
      role="status"
      aria-label="جارٍ التحميل"
    >
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="flex items-center gap-4">
          <Shimmer className="h-10 w-10 rounded-full" />
          <Shimmer className="h-3 flex-1" />
          <Shimmer className="h-3 w-20" />
        </div>
      ))}
      <span className="sr-only">جارٍ التحميل…</span>
    </div>
  );
}

export function CardGridSkeleton({
  count = 6,
  variant = "teacher",
}: {
  count?: number;
  variant?: "teacher" | "course";
}) {
  const Card = variant === "teacher" ? TeacherCardSkeleton : CourseCardSkeleton;

  return (
    <div
      className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"
      role="status"
      aria-label="جارٍ التحميل"
    >
      {Array.from({ length: count }, (_, i) => (
        <Card key={i} />
      ))}
      <span className="sr-only">جارٍ تحميل النتائج…</span>
    </div>
  );
}
