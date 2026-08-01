import type { TrustBand } from "@/lib/public-api";

/**
 * Compact trust score chip.
 *
 * "building" is a first-class state, not a zero. A teacher with too little history
 * has not earned a low score — showing one would drive new teachers off the
 * platform for the crime of being new (FR-024).
 *
 * The medium band is amber; white text on amber sits near 2.4:1, so every band
 * pairs a light fill with dark ink rather than the reverse (FR-081).
 */
const BAND_STYLES: Record<TrustBand, { chip: string; dot: string; label: string }> = {
  high: {
    chip: "bg-trust-high/15 text-trust-high",
    dot: "bg-trust-high",
    label: "ثقة عالية",
  },
  medium: {
    chip: "bg-trust-medium/20 text-trust-medium-foreground dark:text-trust-medium",
    dot: "bg-trust-medium",
    label: "ثقة متوسطة",
  },
  low: {
    chip: "bg-trust-low/15 text-trust-low",
    dot: "bg-trust-low",
    label: "ثقة منخفضة",
  },
  building: {
    chip: "bg-line/60 text-ink-muted",
    dot: "bg-ink-muted",
    label: "قيد التكوين",
  },
};

export function TrustScoreBadge({
  score,
  band,
}: {
  score: number | null;
  band: TrustBand;
}) {
  const style = BAND_STYLES[band];

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${style.chip}`}
    >
      <svg
        className="h-3.5 w-3.5"
        viewBox="0 0 20 20"
        fill="currentColor"
        aria-hidden="true"
      >
        <path
          fillRule="evenodd"
          d="M10 1.5l6.5 2.6v5.1c0 4-2.7 7.7-6.5 8.8-3.8-1.1-6.5-4.8-6.5-8.8V4.1L10 1.5zm3.2 6.1a.75.75 0 10-1.15-.96l-2.9 3.48-1.4-1.4a.75.75 0 10-1.06 1.06l2 2a.75.75 0 001.1-.05l3.4-4.13z"
          clipRule="evenodd"
        />
      </svg>
      {score === null ? (
        <span>{style.label}</span>
      ) : (
        <span>
          {score}
          <span className="opacity-70">٪</span>
        </span>
      )}
      <span className="sr-only">
        {score === null
          ? "درجة الثقة قيد التكوين — لم تتوفّر بيانات كافية بعد"
          : `درجة الثقة ${score} من 100، ${style.label}`}
      </span>
    </span>
  );
}
