import type { TrustBand } from "@/lib/public-api";
import { arabicNumber } from "@/lib/numerals";

const FACTOR_LABELS: Record<string, string> = {
  student_rating: "تقييم الطلاب",
  punctuality: "الالتزام بالمواعيد",
  completion: "إكمال الحصص دون إلغاء",
  tenure: "مدة العمل على المنصة",
  complaints_penalty: "خصم الشكاوى",
};

const BAND_COLOR: Record<TrustBand, string> = {
  high: "text-trust-high-ink",
  medium: "text-trust-medium-ink",
  low: "text-trust-low-ink",
  building: "text-ink-muted",
};

const BAND_STROKE: Record<TrustBand, string> = {
  high: "stroke-trust-high",
  medium: "stroke-trust-medium",
  low: "stroke-trust-low",
  building: "stroke-line",
};

/**
 * The score plus the five factors it is made of.
 *
 * A bare number asks the visitor to trust an opaque figure, which is the exact
 * thing the score exists to avoid. FR-026 requires the plain-language explanation
 * alongside it, so the copy is part of the component, not optional chrome.
 */
export function TrustScoreBreakdown({
  score,
  band,
  factors,
}: {
  score: number | null;
  band: TrustBand;
  factors: Record<string, number> | null;
}) {
  const circumference = 2 * Math.PI * 42;
  const filled = score === null ? 0 : (score / 100) * circumference;

  return (
    <section
      aria-labelledby="trust-heading"
      className="rounded-3xl border border-line bg-surface-raised p-6"
    >
      <h2 id="trust-heading" className="mb-6 text-lg font-bold text-ink">
        درجة الثقة
      </h2>

      {/* Stacked, always. This used to sit in a 380px column beside the
          biography and went side-by-side at `sm`; it now lives in the 320px
          decision column, where "ring beside paragraph" wrapped «الالتزام
          بالمواعيد» onto two lines and squeezed the ring against the text. A
          module that is always narrow should not carry a wide-layout branch. */}
      <div className="flex flex-col items-center gap-6">
        <div className="relative shrink-0">
          <svg className="h-32 w-32 -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
            <circle cx="50" cy="50" r="42" className="fill-none stroke-line" strokeWidth="8" />
            {score !== null && (
              <circle
                cx="50"
                cy="50"
                r="42"
                className={`fill-none ${BAND_STROKE[band]}`}
                strokeWidth="8"
                strokeLinecap="round"
                strokeDasharray={`${filled} ${circumference}`}
              />
            )}
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            {score === null ? (
              <span className="px-3 text-center text-xs font-semibold text-ink-muted">
                قيد التكوين
              </span>
            ) : (
              <>
                <span className={`text-3xl font-extrabold ${BAND_COLOR[band]}`}>
                  {arabicNumber(score)}
                </span>
                <span className="text-xs text-ink-muted">من ١٠٠</span>
              </>
            )}
          </div>
        </div>

        <div className="flex-1">
          {score === null ? (
            <p className="text-sm leading-relaxed text-ink-muted">
              هذا المدرّس جديد على المنصة. تظهر درجة الثقة بعد إكمال ١٠ حصص على
              الأقل والحصول على ٣ تقييمات — حتى ذلك الحين نفضّل ألا نعرض رقماً لا
              تسنده بيانات كافية.
            </p>
          ) : (
            <>
              <p className="mb-5 text-sm leading-relaxed text-ink-muted">
                رقم من ١٠٠ يجمع تقييمات الطلاب والتزام المدرّس بالمواعيد ونسبة إكمال
                الحصص دون إلغاء ومدة عمله على المنصة، ناقص أي شكاوى مؤكدة.
              </p>

              <dl className="space-y-3">
                {Object.entries(factors ?? {}).map(([key, value]) => {
                  const isPenalty = key === "complaints_penalty";

                  return (
                    // dt and dd must be direct children of this wrapper, and the
                    // wrapper a direct child of <dl> — nesting them one level
                    // deeper for layout is what axe's `dlitem` rule catches, and
                    // it breaks the list semantics a screen reader relies on.
                    <div
                      key={key}
                      className="grid grid-cols-[1fr_auto] items-center gap-x-3 text-sm"
                    >
                      <dt className="text-ink">{FACTOR_LABELS[key] ?? key}</dt>
                      <dd className="font-semibold text-ink">
                        {isPenalty ? `−${arabicNumber(value)}` : `${arabicNumber(value)}٪`}
                      </dd>
                      <dd
                        className="col-span-2 mt-1 h-1.5 overflow-hidden rounded-full bg-line"
                        aria-hidden="true"
                      >
                        <span
                          className={`block h-full rounded-full ${isPenalty ? "bg-danger" : "bg-primary"}`}
                          style={{ width: `${isPenalty ? Math.min(value * 5, 100) : value}%` }}
                        />
                      </dd>
                    </div>
                  );
                })}
              </dl>
            </>
          )}
        </div>
      </div>
    </section>
  );
}
