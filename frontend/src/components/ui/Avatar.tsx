/**
 * A person's face, or the first letter of their name.
 *
 * ⚠️ A PLAIN `<img>`, DELIBERATELY, and it is the same call `ReviewsTab` and the
 * participants list already make. `next/image` optimises same-origin and
 * relative paths with no `images` config at all, so a user-supplied avatar path
 * handed to it puts every uploaded photo through `sharp` — whose advisories this
 * repository accepts on the strength of there being no such call site.
 *
 * ⚠️ AND THE FALLBACK IS `aria-hidden`. The name is beside it in every caller, so
 * announcing the initial as well reads the person out twice; `alt=""` on the
 * photo is the same decision.
 */
/**
 * ⚠️ مقاسٌ من مجموعةٍ مغلقة، لا `className` حرّ. مكوّناتُ `components/ui/` لا تأخذُ
 * أصنافاً من مُناديها (قاعدةُ المستودع)، وثلاثةُ مواضعَ تحتاجُ ثلاثةَ أحجام:
 * الشريطُ الجانبيُّ ٣٢، وقائمةُ الحساب ٣٦، وبطاقةُ الإعدادات ٨٠.
 *
 * والصنفُ مكتوبٌ كاملاً في الخريطةِ لا مركَّباً بقالبٍ نصّيّ: Tailwind يمسحُ
 * المصدرَ بحثاً عن أصنافٍ حرفيّة، و`size-${n}` لا يُولِّدُ قاعدةً إطلاقاً — وهي
 * عائلةُ الرمزِ غيرِ المعرَّفِ التي شُحِنَتْ في هذا المستودعِ أربعَ مرّات.
 */
const SIZES = {
  sm: "size-8 text-xs",
  md: "size-9 text-sm",
  lg: "size-20 text-2xl",
} as const;

export function Avatar({
  url,
  name,
  size = "md",
}: {
  url: string | null;
  name: string;
  size?: keyof typeof SIZES;
}) {
  if (url === null) {
    return (
      <span
        aria-hidden="true"
        /*
          ⚠️ `bg-primary-soft`, AND THE CLASS THIS REPLACED WAS `bg-surface-muted`
          — A TOKEN `@theme` HAS NEVER DEFINED. Tailwind v4 emits no rule for one,
          so every avatar with no photo has been a transparent circle with a
          letter floating in it since the participants list shipped: no error, no
          warning, nothing in a snapshot. Fourth time in this tree, and
          `LessonRow` had already written the rule down one directory away.
        */
        className={`flex ${SIZES[size]} shrink-0 items-center justify-center rounded-full bg-primary-soft font-bold text-primary-ink`}
      >
        {name.trim().charAt(0)}
      </span>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={url}
      alt=""
      className={`${SIZES[size]} shrink-0 rounded-full object-cover`}
      loading="lazy"
    />
  );
}
