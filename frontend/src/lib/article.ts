/**
 * ما يُشتقُّ من نصِّ المقالِ — زمنُ القراءةِ وفهرسُ العناوين.
 *
 * ⚠️ **مشتقٌّ لا مُخزَّن، وهذه قاعدةُ المستودعِ لا اختصار.** الخلفيّةُ تُصيِّرُ
 * الـMarkdown عندَ كلِّ استجابةٍ ولا تحفظُ `content_html` أبداً، «لأنّ نسخةً
 * ثانيةً من الكلماتِ نفسِها تفترقُ عن مصدرِها عندَ أوّلِ تصحيحِ خطأٍ مطبعيّ» —
 * وعمودُ «زمن القراءة» أو جدولُ عناوينَ مخزَّنٌ هو تلك النسخةُ الثانيةُ بعينِها.
 *
 * ⚠️ **والوسمُ الخامُّ مُزالٌ عندَ التحليلِ لا مهروب**، فما يصلُ هنا مجموعةُ
 * وسومِ Markdown وحدَها: لا `<script>` ولا سِمةٌ من كتابةِ مدرّس. لذلك يكفي
 * التعبيرُ النمطيُّ أدناه، ولذلك وحدَه.
 */

/**
 * كلمةً في الدقيقةِ للعربيّة.
 *
 * ⚠️ لا ‏٢٠٠ ولا ‏٢٥٠ المتداولتانِ للإنجليزيّة: العربيّةُ تُقرَأُ أبطأَ لكثافةِ
 * الاشتقاقِ وغيابِ التشكيل، والرقمُ المتحفِّظُ يجعلُ الوعدَ أقربَ إلى الصدق. وعدٌ
 * بدقيقتَينِ عن مقالٍ يستغرقُ خمساً أسوأُ من ألّا نَعِد.
 */
const WORDS_PER_MINUTE = 180;

/** عنوانٌ في المقالِ: مستواه، نصُّه، ومِرساتُه. */
export type ArticleHeading = {
  id: string;
  text: string;
  level: 2 | 3;
};

/**
 * مِرساةٌ من نصٍّ عربيّ.
 *
 * ⚠️ **لا `encodeURIComponent` هنا ولا حروفٌ لاتينيّةٌ مفروضة.** المعرِّفُ يُكتَبُ
 * عربيّاً كما هو: المتصفّحُ يُرمِّزُ الجزءَ بعدَ `#` بنفسِه، وترميزُه هنا يُنتِجُ
 * ترميزاً مزدوجاً — وهو العطلُ نفسُه الذي تحرسُ منه صفحةُ المقالِ في رابطِها
 * (`%D8%AE` تصيرُ `%25D8%AE` وكلُّ رابطٍ عربيٍّ ‏٤٠٤).
 *
 * ⚠️ **والفراغُ يصيرُ شرطةً لا يُحذَف**: بلا فاصلٍ تلتصقُ كلمتانِ فيصيرُ عنوانانِ
 * مختلفانِ مِرساةً واحدة.
 */
export function headingSlug(text: string): string {
  return (
    text
      .trim()
      .toLowerCase()
      // التشكيلُ والتطويلُ يُزالان: عنوانٌ مشكولٌ وآخرُ غيرُ مشكولٍ لنفسِ الكلمةِ
      // يجبُ أن يقعا على مِرساةٍ واحدة، وإلّا انكسرَ الرابطُ عندَ أوّلِ تحرير.
      .replace(/[ً-ْـ]/g, "")
      .replace(/[^\p{L}\p{N}]+/gu, "-")
      .replace(/^-+|-+$/g, "") || "قسم"
  );
}

/** نصٌّ مجرَّدٌ من الوسم — للعدِّ لا للعرض. */
function textOf(html: string): string {
  return html
    .replace(/<[^>]*>/g, " ")
    .replace(/&[a-z]+;|&#\d+;/gi, " ")
    .replace(/\s+/g, " ")
    .trim();
}

/**
 * دقائقُ القراءة — دقيقةٌ على الأقلّ.
 *
 * ⚠️ الصفرُ ليس إجابةً: مقالٌ من ثلاثينَ كلمةً يُقرَأُ في وقتٍ ما، و«‏٠ دقيقة»
 * تُقرَأُ عطلاً في العرضِ لا وصفاً للمقال.
 */
export function readingMinutes(html: string): number {
  const words = textOf(html).split(" ").filter(Boolean).length;

  return Math.max(1, Math.round(words / WORDS_PER_MINUTE));
}

/** عددُ الكلماتِ — يسافرُ في `wordCount` ضمنَ البياناتِ المنظَّمة. */
export function wordCount(html: string): number {
  return textOf(html).split(" ").filter(Boolean).length;
}

/**
 * حقنُ مِرساةٍ في كلِّ عنوانٍ، وإخراجُ الفهرسِ معه.
 *
 * ⚠️ **الاثنانِ من مرورٍ واحد.** فهرسٌ يُبنى في دالّةٍ ومِرساةٌ تُحقَنُ في أخرى
 * هجاءانِ لخوارزميّةٍ واحدةٍ يفترقانِ عندَ أوّلِ عنوانٍ مكرَّر — فيشيرُ الفهرسُ
 * إلى مِرساةٍ لا وجودَ لها، ولا خطأَ في أيِّ مكان.
 *
 * ⚠️ **والمكرَّرُ يأخذُ لاحقةً**: عنوانانِ بالنصِّ نفسِه (وهو شائعٌ: «تمارين»
 * مرّتان) يُنتِجانِ `id` واحداً، فيقفزُ الرابطانِ إلى الموضعِ الأوّل — والقارئُ
 * يظنُّ الفهرسَ معطّلاً.
 *
 * ⚠️ **وإن حملَ العنوانُ `id` سلفاً تُرِكَ كما هو**: الكاتبُ أدرى، والاستبدالُ
 * يكسرُ رابطاً خارجيّاً يشيرُ إليه.
 */
export function withHeadingAnchors(html: string): {
  html: string;
  headings: ArticleHeading[];
} {
  const headings: ArticleHeading[] = [];
  const used = new Map<string, number>();

  const withIds = html.replace(
    /<h([23])([^>]*)>([\s\S]*?)<\/h\1>/gi,
    (match, level: string, attributes: string, inner: string) => {
      const text = textOf(inner);

      if (text === "") return match;

      const existing = /\sid=["']([^"']+)["']/i.exec(attributes);

      let id = existing?.[1] ?? headingSlug(text);

      if (existing === null) {
        const seen = used.get(id) ?? 0;

        used.set(id, seen + 1);

        if (seen > 0) id = `${id}-${seen + 1}`;
      }

      headings.push({ id, text, level: level === "2" ? 2 : 3 });

      return existing === null
        ? `<h${level}${attributes} id="${id}">${inner}</h${level}>`
        : match;
    },
  );

  return { html: withIds, headings };
}

/**
 * مدّةُ ISO‑8601 لـ`timeRequired` في Schema.org.
 *
 * محرّكاتُ الإجابةِ تقرأُ هذا الحقلَ حرفيّاً، و«‏٥ دقائق» نصٌّ عربيٌّ لا مدّة.
 */
export function isoMinutes(minutes: number): string {
  return `PT${minutes}M`;
}
