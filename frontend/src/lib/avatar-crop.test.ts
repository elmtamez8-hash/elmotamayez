import { describe, expect, it } from "vitest";

import { clampOffset, clampZoom, coverScale, sourceRect } from "./avatar-crop";

/*
| قصُّ صورةِ الحساب — طلبُ ٢٠٢٦-٠٩-٠٦: «ظبط الصورة وتقليل حجمها وعمل كروب لها
| وجعل وجه اليوزر في الفريم الدائري».
|
| ⚠️ الهندسةُ وحدَها مقيسةٌ هنا لأنّها وحدَها ما يستطيعُ `jsdom` قياسَه: لا
| `getContext` ولا `toBlob` في هذه البيئة. وهو أيضاً حيثُ يعيشُ العطب — التغطيةُ
| مقابلَ الاحتواء، وتقييدُ الإزاحةِ عندَ الحواف — بينما `exportCrop` سطرا رسم.
*/
describe("coverScale", () => {
  it("COVERS the frame rather than containing it", () => {
    // ⚠️ الفرقُ كلُّه. صورةٌ ٢٠٠٠×١٠٠٠ في نافذةِ ٢٠٠: الاحتواءُ يعطي ٠٫١ فيبقى
    // نصفُ الدائرةِ فارغاً، والتغطيةُ تعطي ٠٫٢ فتمتلئُ — وكلُّ صورةِ هاتفٍ
    // مستطيلة.
    expect(coverScale({ naturalWidth: 2000, naturalHeight: 1000, view: 200 })).toBe(0.2);
    expect(coverScale({ naturalWidth: 1000, naturalHeight: 2000, view: 200 })).toBe(0.2);
    expect(coverScale({ naturalWidth: 100, naturalHeight: 100, view: 200 })).toBe(2);
  });

  it("does not divide by a zero the browser hands over before load", () => {
    // `naturalWidth` هو صفرٌ حتّى تُحمَّلَ الصورة، ولحظةُ الرسمِ الأولى تقعُ قبلَ
    // ذلك — و`Infinity` تنتشرُ في كلِّ حسابٍ بعدَها بلا خطأٍ واحد.
    expect(coverScale({ naturalWidth: 0, naturalHeight: 0, view: 200 })).toBe(1);
  });
});

describe("clampZoom", () => {
  it("holds the slider inside its two ends", () => {
    expect(clampZoom(0.2)).toBe(1);
    expect(clampZoom(9)).toBe(4);
    expect(clampZoom(2.5)).toBe(2.5);
    expect(clampZoom(Number.NaN)).toBe(1);
  });
});

describe("clampOffset", () => {
  // صورةٌ عرضُها ٢٠٠٠ بمقياسِ ٠٫٢ = ٤٠٠ بكسل، في نافذةِ ٢٠٠ ⇒ السحبُ بينَ −٢٠٠ و٠.
  it("never lets a drag expose an empty edge", () => {
    expect(clampOffset(50, 2000, 0.2, 200)).toBe(0);
    expect(clampOffset(-500, 2000, 0.2, 200)).toBe(-200);
    expect(clampOffset(-120, 2000, 0.2, 200)).toBe(-120);
  });

  it("centres rather than exposing an edge when the image is not wider than the frame", () => {
    // لا يحدثُ بعدَ `coverScale` إلّا بخطأِ تقريبٍ عائم؛ والجوابُ صفرٌ لا قيمةٌ
    // موجبةٌ تدفعُ الصورةَ داخلَ النافذةِ وتكشفُ خلفَها.
    expect(clampOffset(30, 100, 1, 200)).toBe(0);
  });
});

describe("sourceRect", () => {
  it("maps the frame back onto the original pixels", () => {
    // ٢٠٠٠×١٠٠٠ في نافذةِ ٢٠٠ بلا تكبيرٍ إضافيّ: المقياسُ ٠٫٢، فالمربّعُ المرئيُّ
    // ١٠٠٠ بكسلٍ من المصدر — أي ارتفاعُ الصورةِ كلُّه، وهو الصحيح.
    const rect = sourceRect(
      { naturalWidth: 2000, naturalHeight: 1000, view: 200 },
      { zoom: 1, offsetX: 0, offsetY: 0 },
    );

    expect(rect).toEqual({ sx: 0, sy: 0, size: 1000 });
  });

  it("moves the window over the source when the teacher drags", () => {
    const rect = sourceRect(
      { naturalWidth: 2000, naturalHeight: 1000, view: 200 },
      { zoom: 1, offsetX: -100, offsetY: 0 },
    );

    // −١٠٠ بكسلَ عرضٍ عندَ مقياسِ ٠٫٢ = ٥٠٠ بكسلٍ من المصدر.
    expect(rect.sx).toBe(500);
    expect(rect.size).toBe(1000);
  });

  it("shrinks the window as the zoom grows, and clamps the drag with it", () => {
    // ⚠️ التقييدُ يُعادُ حسابُه بالمقياسِ الجديدِ لا بالقديم: تكبيرٌ بعدَ سحبٍ إلى
    // الحافّةِ يترُكُ الإزاحةَ القديمةَ صالحةً، لكنّ تصغيراً بعدَه يجعلُها خارجَ
    // الحدِّ — فتُكشَفُ حافّةٌ فارغةٌ داخلَ الدائرة.
    const wide = sourceRect(
      { naturalWidth: 2000, naturalHeight: 1000, view: 200 },
      { zoom: 2, offsetX: -5000, offsetY: -5000 },
    );

    expect(wide.size).toBe(500);
    // المقياسُ ٠٫٤، والصورةُ ٨٠٠×٤٠٠ بكسل، فالحدُّ −٦٠٠ و−٢٠٠ ⇒ ١٥٠٠ و٥٠٠.
    expect(wide.sx).toBe(1500);
    expect(wide.sy).toBe(500);
  });
});
