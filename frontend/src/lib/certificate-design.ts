/**
 * رسمُ الشهادةِ على صورتِها — الحسابُ وحدَه، بلا DOM.
 *
 * ⚠️ هذه الدالّاتُ **تأخذُ الأبعادَ وسيطاً** ولا تسألُ عنصراً عن عرضِه، وذلك ليس
 * ترتيباً جميلاً بل الشرطَ الوحيدَ الذي يجعلُ مُلاءمةَ الخطِّ مقيسة: `jsdom` **لا
 * يخطّطُ إطلاقاً** — كلُّ `offsetWidth` صفرٌ وكلُّ `getBoundingClientRect()`
 * أصفار — فمنطقٌ مكتوبٌ داخلَ المكوّنِ لا يستطيعُ `npm test` أن يقتربَ منه، وهو
 * بالضبط المكانُ الذي يعيشُ فيه العطبُ (اسمٌ يتخطّى مستطيلَه). درسُ `avatar-crop.ts`.
 */

/** المفاتيحُ الستّةُ حصراً. */
export const FIELD_KEYS = ["student", "subject", "teacher", "date", "number", "qr"] as const;

export type FieldKey = (typeof FIELD_KEYS)[number];

export interface FieldBox {
  /** نسبةً إلى الصورة (0–1): الزاويةُ العليا للصندوق. */
  x: number;
  y: number;
  w: number;
  h: number;
  align?: "start" | "center" | "end";
  /** نسبةٌ من ارتفاعِ الصورة. */
  max_font?: number;
  min_font?: number;
  /** مفتاحُ رمزٍ من `@theme`، لا سداسيّ. */
  color?: string;
}

export interface DesignPayload {
  image_url: string;
  boxes: Record<FieldKey, FieldBox>;
}

/** ما يُكتَبُ في الصناديقِ الخمسةِ النصّيّة، ثمّ عنوانُ التحقّقِ للرمز. */
export interface CertificateValues {
  student: string;
  subject: string;
  teacher: string;
  date: string;
  number: string;
  verifyUrl: string;
}

/** قياسُ عرضِ نصٍّ بحجمِ خطٍّ معيّن، بالبكسل. */
export type Measure = (text: string, fontSizePx: number) => number;

/** ارتفاعُ السطرِ نسبةً إلى حجمِ الخطّ — قيمةٌ واحدةٌ يقرؤها الحسابُ والرسمُ معاً. */
export const LINE_HEIGHT = 1.2;

/** أكثرُ من ثلاثةِ أسطرٍ ليس اسماً، وصندوقُ الشهادةِ ليس فقرة. */
const MAX_LINES = 3;

/** موضعُ صندوقٍ بالبكسل داخلَ صورةٍ بأبعادٍ معلومة. */
export function boxToStyle(
  box: FieldBox,
  width: number,
  height: number,
): { left: number; top: number; width: number; height: number } {
  return {
    left: box.x * width,
    top: box.y * height,
    width: box.w * width,
    height: box.h * height,
  };
}

/**
 * يُقيَّدُ الصندوقُ داخلَ الصورة (`FR-020`).
 *
 * ⚠️ يُقيَّدُ **عندَ التخزينِ لا عندَ الرسم**: صندوقٌ مخزَّنٌ خارجَ الحدودِ يُصلَّحُ
 * في كلِّ قارئٍ على حِدَة، فينسى أحدُهم يوماً.
 */
export function clampBox(box: FieldBox): FieldBox {
  const w = clamp(box.w, 0.01, 1);
  const h = clamp(box.h, 0.01, 1);
  const x = clamp(box.x, 0, 1 - w);
  const y = clamp(box.y, 0, 1 - h);

  return { ...box, x, y, w, h };
}

export interface FittedText {
  /** حجمُ الخطِّ بالبكسل. */
  fontSize: number;
  /** الأسطرُ كما تُرسَم — النصُّ كاملاً دائماً، بلا قصٍّ ولا نقاطِ اختصار. */
  lines: string[];
}

/**
 * أكبرُ حجمِ خطٍّ يجعلُ النصَّ **كاملاً** داخلَ الصندوق (SC-002 · `FR-023`…`FR-027`).
 *
 * ثلاثُ قواعدَ يسهلُ فقدُ إحداها:
 * - **لا تكبيرَ فوقَ `max_font`** — اسمٌ من ثلاثةِ محارفَ لا يُملأُ به الصندوق.
 * - **لا نزولَ تحتَ `min_font` ما دامَ الالتفافُ ممكناً** — يُجرَّبُ سطرٌ ثمَّ
 *   سطرانِ ثمَّ ثلاثة، ويُؤخَذُ أوّلُ ما يُرضي الحدَّ الأدنى.
 * - **ولا قصَّ إطلاقاً** إن عجزَ الثلاثةُ: يُؤخَذُ أكبرُ حجمٍ يسعُ النصَّ ولو نزلَ
 *   تحتَ الأدنى — نصٌّ صغيرٌ يُقرأُ بجهد، ونصٌّ مقصوصٌ اسمٌ خطأ.
 */
export function fitText(
  text: string,
  box: FieldBox,
  width: number,
  height: number,
  measure: Measure,
): FittedText {
  const boxWidth = box.w * width;
  const boxHeight = box.h * height;
  const maxFont = (box.max_font ?? box.h) * height;
  const minFont = (box.min_font ?? 0) * height;

  const trimmed = text.trim();

  if (trimmed === "" || boxWidth <= 0 || boxHeight <= 0) {
    return { fontSize: maxFont, lines: trimmed === "" ? [] : [trimmed] };
  }

  let best: FittedText | null = null;

  for (let count = 1; count <= MAX_LINES; count += 1) {
    const lines = wrap(trimmed, count);

    // A word that cannot be split means the extra line buys nothing.
    if (lines.length < count) break;

    const ceiling = Math.min(maxFont, boxHeight / (count * LINE_HEIGHT));
    const fontSize = largestFitting(lines, boxWidth, ceiling, measure);
    const candidate = { fontSize, lines };

    if (fontSize >= minFont) return candidate;
    if (best === null || fontSize > best.fontSize) best = candidate;
  }

  return best ?? { fontSize: minFont, lines: [trimmed] };
}

/**
 * بحثٌ ثنائيٌّ عن أكبرِ حجمٍ يسعُ كلَّ سطر.
 *
 * ⚠️ بحثٌ لا قسمةٌ على العرضِ المقيس: `measureText` ليس خطّيّاً تماماً في حجمِ
 * الخطّ — التقريبُ والمحاذاةُ الشبكيّةُ يكسرانِ الخطّيّةَ عندَ الأحجامِ الصغيرة —
 * فقسمةٌ واحدةٌ تعطي حجماً يتخطّى الحافّةَ ببكسلٍ أو اثنَين، وهو بالضبط ما يبدو
 * على الورقةِ المطبوعة.
 */
function largestFitting(lines: string[], boxWidth: number, ceiling: number, measure: Measure): number {
  const fits = (size: number): boolean => lines.every((line) => measure(line, size) <= boxWidth);

  if (fits(ceiling)) return ceiling;

  let low = 0;
  let high = ceiling;

  for (let i = 0; i < 24; i += 1) {
    const mid = (low + high) / 2;

    if (fits(mid)) low = mid;
    else high = mid;
  }

  return low;
}

/** توزيعٌ متوازنٌ للكلماتِ على عددٍ من الأسطر. */
function wrap(text: string, count: number): string[] {
  if (count <= 1) return [text];

  const words = text.split(/\s+/).filter(Boolean);

  if (words.length < count) return [text];

  const target = Math.ceil(text.length / count);
  const lines: string[] = [];
  let current = "";

  for (const word of words) {
    const next = current === "" ? word : `${current} ${word}`;

    if (current !== "" && next.length > target && lines.length < count - 1) {
      lines.push(current);
      current = word;
    } else {
      current = next;
    }
  }

  if (current !== "") lines.push(current);

  return lines;
}

function clamp(value: number, min: number, max: number): number {
  if (!Number.isFinite(value)) return min;

  return Math.min(Math.max(value, min), max);
}

/**
 * قياسٌ حقيقيٌّ عبرَ `canvas` حينَ يوجد، وتقديرٌ متحفّظٌ حينَ لا يوجد.
 *
 * ⚠️ `jsdom` بلا `canvas` إطلاقاً — لا `getContext` — فالتقديرُ هو ما يعملُ في
 * الاختبار، والمعاملُ فيه **متحفّظٌ عمداً**: تقديرٌ أعرضُ من الحقيقةِ يُصغّرُ الخطَّ
 * قليلاً، وتقديرٌ أضيقُ يجعلُ الاسمَ يتخطّى الصندوق.
 */
const measurers = new Map<string, Measure>();

export function createMeasure(fontFamily = "sans-serif"): Measure {
  const cached = measurers.get(fontFamily);

  if (cached !== undefined) return cached;

  const measure = buildMeasure(fontFamily);

  measurers.set(fontFamily, measure);

  return measure;
}

function buildMeasure(fontFamily: string): Measure {
  // ⚠️ try/catch, not a feature check: `getContext` EXISTS in jsdom and throws
  // rather than returning null, so `?.` alone still crashes the render.
  let context: CanvasRenderingContext2D | null = null;

  try {
    context = typeof document === "undefined"
      ? null
      : document.createElement("canvas").getContext("2d");
  } catch {
    context = null;
  }

  if (context === null) {
    return (text, fontSizePx) => text.length * fontSizePx * 0.62;
  }

  const ctx = context;
  const family = acceptedFamily(ctx, canvasFontFamily(fontFamily));

  return (text, fontSizePx) => {
    ctx.font = `700 ${fontSizePx}px ${family}`;

    return ctx.measureText(text).width;
  };
}

/**
 * The family this canvas will actually honour.
 *
 * ⚠️ AN INVALID FONT STRING IS A SILENT NO-OP, AND IT MADE EVERY MEASUREMENT A
 * LIE. `ctx.font` is a CSS parse: it rejects what it cannot parse and KEEPS its
 * previous value, which starts at `10px sans-serif` — so every width came back
 * measured at ten pixels whatever size was asked for, every candidate «fitted»,
 * and `fitText` returned `max_font` for a name twice too wide. Measured live on
 * 2026-09-06: a 40-character name overflowed its box by 105px on the public page
 * while every unit test was green, because jsdom has no canvas and falls back to
 * the heuristic above, which does scale.
 *
 * ⚠️ AND THE READBACK IS DONE ONCE, AT A WHOLE NUMBER. Checking it on every call
 * against a FRACTIONAL size is the same bug wearing a smaller coat: the canvas
 * normalises `18.399999px` to `18.4px`, the string comparison fails, and the
 * measurement silently falls back to a narrower generic family — 58 characters
 * then overflowed by 108px with the size looking perfectly reasonable.
 */
function acceptedFamily(ctx: CanvasRenderingContext2D, family: string): string {
  ctx.font = `700 100px ${family}`;

  return ctx.font.includes("100px") ? family : "sans-serif";
}

/**
 * A family list a CANVAS can parse.
 *
 * ⚠️ `var(--font-sans)` IS VALID CSS AND INVALID HERE. Canvas parses the font
 * shorthand on its own, with no element and therefore no custom properties to
 * resolve — so a stack carrying one is rejected whole, taking the concrete
 * fallbacks beside it down with it. Custom properties are dropped and a generic
 * family is kept as the floor; the caller passes the RESOLVED family
 * (`getComputedStyle(...).fontFamily`) so the measurement matches what is drawn.
 */
export function canvasFontFamily(fontFamily: string): string {
  const concrete = fontFamily
    .split(",")
    .map((part) => part.trim())
    .filter((part) => part !== "" && !part.startsWith("var("));

  return concrete.length > 0 ? concrete.join(", ") : "sans-serif";
}
