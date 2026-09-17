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
  tone = "default",
}: {
  value: number | null;
  count?: number;
  size?: "sm" | "lg";
  /**
   * `overlay` — مرسومٌ فوقَ صورةٍ خلفَ تدرّجٍ داكن.
   *
   * ⚠️ **مجموعةٌ مغلقةٌ لا `className`**: واجهةُ هذا المجلَّدِ لا تقبلُ صنفاً
   * حُرّاً، والسببُ هنا ملموس — الرموزُ الافتراضيّة (`text-line` للنجمةِ
   * الفارغةِ و`text-ink-muted` للعدد) معايَرةٌ لأرضيّةٍ فاتحة، وفوقَ التدرّجِ
   * تختفي. فالبديلُ ليس لوناً يختارُه المُنادي بل حالةٌ يعرفُها المكوِّن.
   *
   * والأبيضُ هنا فوقَ حاجبٍ أسودَ لا فوقَ لون: وهي التهجئةُ التي قاسَها
   * `PageBanner` وكتبَ أرقامَها — الحاجبُ يتكفّلُ بالتباينِ فتخرجُ الصورةُ من
   * الحساب. والنجمةُ الذهبيّةُ تبقى ذهبيّةً في الحالتَين.
   */
  tone?: "default" | "overlay";
}) {
  const dimension = size === "lg" ? "h-5 w-5" : "h-4 w-4";
  const empty = tone === "overlay" ? "text-white/45" : "text-line";
  const muted = tone === "overlay" ? "text-white/85" : "text-ink-muted";
  const strong = tone === "overlay" ? "text-white" : "text-ink";

  /*
    ⚠️ **الخمسُ نجماتٍ تُرسَمُ ولو لم يقيّمْ أحد.** كانت الحالةُ الفارغةُ جملةً
    عاريةً بلا شكل، فكارتٌ بلا تقييمٍ يختلفُ عن جارِه في الارتفاعِ وفي البنيةِ
    معاً — والعينُ تقرأُ الفرقَ عيباً في الصفِّ لا خبراً عن الكورس. والشكلُ
    الفارغُ يقولُ «هنا مكانُ التقييم» بينما الغيابُ لا يقولُ شيئاً.
  */
  if (value === null) {
    return (
      <span className="flex items-center gap-1.5">
        <span className="flex" aria-hidden="true">
          {[1, 2, 3, 4, 5].map((star) => (
            <StarIcon key={star} className={`${dimension} ${empty}`} />
          ))}
        </span>
        {/*
          ⚠️ **صفرُ عددٍ لا صفرُ درجة، والفرقُ هو كلُّ شيء.** «٠» مكتوبةً مكانَ
          الدرجةِ تقولُ «قُيِّمَ بصفرٍ من خمسة» — حكمٌ على الكورسِ لم يُصدِرْه
          أحد؛ وهذا المستودعُ يسجّلُ القاعدةَ بنصِّها في درجةِ الثقة: «ما دونَ
          عتبةِ البيانات `null` ببندِ `building`، لا صفراً أبداً».
          والقوسانِ يضعانِها حيثُ يضعُها كلُّ موقع: عدَدَ المراجعات.
        */}
        <span className={`text-sm ${muted}`}>({arabicNumber(0)})</span>
        <span className="sr-only">لا توجد تقييمات بعد</span>
      </span>
    );
  }

  const rounded = Math.round(value * 2) / 2;

  return (
    <span className="flex items-center gap-1.5">
      <span className="flex" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((star) => (
          <StarIcon
            key={star}
            className={`${dimension} ${star <= rounded ? "fill-star text-star" : empty}`}
          />
        ))}
      </span>
      <span className={`${strong} ${size === "lg" ? "text-base font-semibold" : "text-sm font-medium"}`}>
        {arabicDecimal(value)}
      </span>
      {count !== undefined && (
        <span className={`text-sm ${muted}`}>({arabicNumber(count)})</span>
      )}
      <span className="sr-only">
        {`التقييم ${arabicDecimal(value)} من ٥`}
        {count !== undefined ? ` بناءً على ${arabicNumber(count)} تقييماً` : ""}
      </span>
    </span>
  );
}
