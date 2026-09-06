import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { AvatarCropper } from "./AvatarCropper";

/*
| ⚠️ «الصورة مش بعرف احركها بسهولة، بتتحرك في اتجاه واحد فقط» — بلاغُ المستخدِمِ
| ٢٠٢٦-٠٩-٠٦، من استعمالٍ حقيقيٍّ للشاشة.
|
| سببانِ متضافران، وكلاهما يقرأُ صحيحاً في الشيفرة:
|
|   ١. التقييدُ كانَ عندَ الرسمِ وحدَه، فالمخزَّنُ ينجرفُ بلا حدّ: سحبٌ يتجاوزُ
|      الحافّةَ يُراكِمُ −٥٠٠٠ بينما المرسومُ واقفٌ عندَ −٦٠٠، فالسحبُ المعاكسُ
|      يفكُّ ٤٤٠٠ بكسلٍ قبلَ أن تتحرّكَ الصورةُ بكسلاً — حركةٌ في اتّجاهٍ واحد.
|   ٢. `setOffset` بقيمةٍ محسوبةٍ من `x` الرسمِ يدوسُ الأحداثَ المتتاليةَ بينَ
|      رسمتَين بدلَ أن يُراكِمَها، فتبدو الصورةُ ثقيلةً لاصقة.
|
| ⚠️ ولم يكنْ أيٌّ منهما ليظهرَ في `avatar-crop.test.ts`: الدوالُّ هناك صحيحةٌ
| تماماً، والعطبُ في **كيفيّةِ استدعائِها عبرَ أحداثٍ متتالية**. فالقياسُ هنا على
| عدّةِ `pointermove` لا على واحد — حدثٌ واحدٌ يمرُّ أخضرَ على البناءِ المعطوبِ
| نفسِه.
*/

/** الحقولُ الثلاثةُ التي يقرؤها المكوّنُ من صورةٍ محمَّلة، ولا شيءَ غيرَها. */
const IMAGE = { naturalWidth: 1500, naturalHeight: 500, src: "blob:fake" };

function mount() {
  const { container } = render(
    <AvatarCropper
      image={IMAGE as unknown as HTMLImageElement}
      onCancel={vi.fn()}
      onCrop={vi.fn()}
      onError={vi.fn()}
    />,
  );

  const stage = container.querySelector("div.rounded-full") as HTMLElement;
  const img = container.querySelector("img") as HTMLImageElement;

  return { stage, img };
}

/** `translate(-40px, 0px)` ⇐ `[-40, 0]` */
function translation(img: HTMLImageElement): [number, number] {
  const match = /translate\((-?[\d.]+)px, (-?[\d.]+)px\)/.exec(img.style.transform);

  return [Number(match?.[1]), Number(match?.[2])];
}

function drag(stage: HTMLElement, steps: Array<[number, number]>) {
  fireEvent.pointerDown(stage, { clientX: 0, clientY: 0, pointerId: 1 });

  for (const [x, y] of steps) {
    fireEvent.pointerMove(stage, { clientX: x, clientY: y, pointerId: 1 });
  }

  fireEvent.pointerUp(stage, { pointerId: 1 });
}

describe("dragging the picture inside the circle", () => {
  it("ACCUMULATES successive moves instead of overwriting them", () => {
    const { stage, img } = mount();

    // مقيسٌ من البدايةِ لا من صفر: المحرّرُ يفتحُ موسَّطاً، وتوكيدٌ يثبّتُ رقماً
    // مطلقاً يقيسُ التوسيطَ لا السحب.
    const [before] = translation(img);

    // ثلاثةُ أحداثٍ بينَ رسمتَين — وهو ما يفعلُهُ المتصفّحُ في كلِّ سحبةٍ حقيقيّة.
    // على البناءِ المعطوبِ يتحرّكُ بـ٢٠− (آخرُ فرقٍ وحدَه) بدلَ ٦٠−.
    drag(stage, [
      [-20, 0],
      [-40, 0],
      [-60, 0],
    ]);

    expect(translation(img)[0]).toBe(before - 60);
  });

  it("stops AT the edge rather than drifting past it, so the way back is immediate", () => {
    const { stage, img } = mount();

    // ١٥٠٠×٥٠٠ في نافذةِ ٢٦٠: المقياسُ ٠٫٥٢، فالعرضُ ٧٨٠ والحدُّ −٥٢٠.
    drag(stage, [[-3000, 0]]);

    expect(translation(img)[0]).toBe(-520);

    // ⚠️ التوكيدُ الحاسم. لو خُزِّنَ −٣٠٠٠ لَما تحرّكتِ الصورةُ هنا بكسلاً واحداً
    // — وهو بالضبطِ ما بلّغَ عنه المستخدِم.
    drag(stage, [[100, 0]]);

    expect(translation(img)[0]).toBe(-420);
  });

  it("does not move the axis the image only just covers, and that is correct", () => {
    // ١٥٠٠×٥٠٠ عندَ أدنى تكبير: الارتفاعُ ٢٦٠ بالضبط = النافذة، فلا فُسحةَ رأسيّة
    // إطلاقاً. `coverScale` تعريفٌ لا عطب — والتكبيرُ هو ما يفتحُ المحورَ الثاني.
    const { stage, img } = mount();

    drag(stage, [[0, -80]]);

    expect(translation(img)[1]).toBe(0);
  });

  it("opens the second axis once zoomed, and re-clamps the drag to the new scale", () => {
    const { stage, img } = mount();

    fireEvent.change(screen.getByRole("slider"), { target: { value: "2" } });

    drag(stage, [[0, -80]]);

    expect(translation(img)[1]).toBe(-80);

    // ⚠️ والتصغيرُ بعدَ سحبٍ إلى الحافّةِ يُعيدُ التقييدَ بالمقياسِ الجديد. بدونَه
    // يبقى المخزَّنُ صالحاً للقديمِ وحدَه، فيبدأُ السحبُ التالي من قيمةٍ خارجَ
    // الحدّ — الاتّجاهُ الواحدُ عائداً من بابٍ آخر.
    fireEvent.change(screen.getByRole("slider"), { target: { value: "1" } });

    expect(translation(img)[1]).toBe(0);
  });
});
