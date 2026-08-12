import { StarIcon } from "@/components/icons";
import { arabicDecimal, arabicNumber } from "@/lib/numerals";
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
          <StarIcon
            key={star}
            className={`${dimension} ${star <= rounded ? "text-accent" : "text-line"}`}
          />
        ))}
      </span>
      <span className={size === "lg" ? "text-base font-semibold" : "text-sm font-medium"}>
        {arabicDecimal(value)}
      </span>
      {count !== undefined && (
        <span className="text-sm text-ink-muted">({arabicNumber(count)})</span>
      )}
      <span className="sr-only">
        {`التقييم ${arabicDecimal(value)} من ٥`}
        {count !== undefined ? ` بناءً على ${arabicNumber(count)} تقييماً` : ""}
      </span>
    </span>
  );
}
