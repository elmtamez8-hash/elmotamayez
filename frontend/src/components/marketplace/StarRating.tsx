/**
 * Star rating with a text equivalent.
 *
 * The stars are aria-hidden and the real value is exposed as text, because a
 * screen reader announcing "star star star star" conveys nothing about the score.
 */
export function StarRating({
  value,
  count,
  size = "sm",
}: {
  value: number | null;
  count?: number;
  size?: "sm" | "lg";
}) {
  if (value === null) {
    return (
      <span className="text-sm text-ink-muted">لا توجد تقييمات بعد</span>
    );
  }

  const dimension = size === "lg" ? "h-5 w-5" : "h-4 w-4";
  const rounded = Math.round(value * 2) / 2;

  return (
    <span className="flex items-center gap-1.5">
      <span className="flex" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((star) => (
          <svg
            key={star}
            className={`${dimension} ${star <= rounded ? "text-accent" : "text-line"}`}
            viewBox="0 0 20 20"
            fill="currentColor"
          >
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.367 2.446a1 1 0 00-.363 1.118l1.286 3.958c.3.921-.755 1.688-1.539 1.118l-3.366-2.446a1 1 0 00-1.176 0l-3.367 2.446c-.783.57-1.838-.197-1.538-1.118l1.286-3.958a1 1 0 00-.363-1.118L2.063 9.385c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.951-.69l1.285-3.958z" />
          </svg>
        ))}
      </span>
      <span className={size === "lg" ? "text-base font-semibold" : "text-sm font-medium"}>
        {value.toFixed(1)}
      </span>
      {count !== undefined && (
        <span className="text-sm text-ink-muted">({count})</span>
      )}
      <span className="sr-only">
        {`التقييم ${value.toFixed(1)} من 5`}
        {count !== undefined ? ` بناءً على ${count} تقييماً` : ""}
      </span>
    </span>
  );
}
