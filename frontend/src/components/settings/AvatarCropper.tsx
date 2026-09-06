"use client";

import { useRef, useState } from "react";

import { Button } from "@/components/ui/Button";
import {
  MAX_ZOOM,
  MIN_ZOOM,
  centredOffset,
  clampOffset,
  clampZoom,
  coverScale,
  exportCrop,
  sourceRect,
} from "@/lib/avatar-crop";

/**
 * ضبطُ الصورةِ داخلَ الإطارِ الدائريِّ قبلَ رفعِها.
 *
 * ⚠️ الدائرةُ **قناعٌ لا محتوى**: المرفوعُ مربَّعٌ، والخادمُ يُعيدُ ترميزَه مربَّعاً
 * ٥١٢، والدائرةُ تُرسَمُ بالـCSS في التسعِ شاشاتٍ التي تقرأُ هذا العمود. صورةٌ
 * دائريّةٌ فعلاً تعني شفافيّةً تعني PNG بدلَ JPEG — ملفٌّ أكبرُ لمشكلةٍ لا وجودَ
 * لها، وحافّةٌ مقصوصةٌ لا يمكنُ التراجعُ عنها لو تغيّرَ الشكلُ يوماً.
 *
 * ⚠️ والحسابُ كلُّه في `lib/avatar-crop.ts`: `jsdom` بلا لوحةٍ إطلاقاً، فما يُكتَبُ
 * هنا لا يستطيعُ `npm test` قياسَه. هذا الملفُّ أحداثُ مؤشِّرٍ ونداءان.
 *
 * ponytail: لا تكبيرَ بالقرصِ (pinch) — الشريطُ يغطّي الهاتفَ والحاسوب، ويُضافُ
 * `touch-action: none` وحدثانِ لو طُلِبَ فعلاً.
 */

/** ضلعُ نافذةِ المعاينة. ثابتٌ لأنّ الحسابَ كلَّه نسبةٌ إليه. */
const VIEW = 260;

export function AvatarCropper({
  image,
  busy = false,
  onCancel,
  onCrop,
  onError,
}: {
  image: HTMLImageElement;
  busy?: boolean;
  onCancel: () => void;
  onCrop: (blob: Blob) => void;
  /**
   * ⚠️ وليس `void promise`. وعدٌ مرفوضٌ بلا ملتقطٍ يرسمُ في التطويرِ
   * خطأً خاماً فوقَ الصفحة، وفي الإنتاجِ **لا يفعلُ شيئاً إطلاقاً** — زرٌّ
   * يُضغَطُ ولا يحدث. و`toBlob` يرفضُ فعلاً: لوحةٌ ملوّثةٌ أو ذاكرةٌ لا تكفي
   * لصورةٍ من هاتفٍ حديث.
   */
  onError: (message: string) => void;
}) {
  const frame = {
    naturalWidth: image.naturalWidth,
    naturalHeight: image.naturalHeight,
    view: VIEW,
  };

  const [zoom, setZoom] = useState(MIN_ZOOM);
  // مُهيَّأةٌ مرّةً واحدةً بدالّة: المكوّنُ يُركَّبُ من جديدٍ لكلِّ صورةٍ تُختار،
  // فلا حاجةَ إلى أثرٍ يُعيدُ التوسيطَ عندَ التغيير.
  const [offset, setOffset] = useState(() => centredOffset(frame, coverScale(frame) * MIN_ZOOM));
  const drag = useRef<{ x: number; y: number } | null>(null);
  const scale = coverScale(frame) * clampZoom(zoom);

  /** الإزاحةُ المخزَّنةُ مقيَّدةٌ سلفاً؛ وهذا حزامُ أمانٍ لأوّلِ رسمٍ ولا أكثر. */
  const x = clampOffset(offset.x, frame.naturalWidth, scale, VIEW);
  const y = clampOffset(offset.y, frame.naturalHeight, scale, VIEW);

  /**
   * الإزاحةُ تُقيَّدُ عندَ **التخزينِ**، والفروقُ تُراكَمُ بمُحدِّثٍ دالّيّ.
   *
   * ⚠️ العطبُ الذي كانَ هنا بلاغُ مستخدِمٍ: «الصورة مش بعرف احركها بسهولة، بتتحرك
   * في اتجاه واحد فقط». وله سببانِ متضافران، وكلاهما غيرُ مرئيٍّ في قراءةِ الشيفرة:
   *
   *   ١. **التقييدُ عندَ الرسمِ وحدَه يجعلُ المخزَّنَ ينجرفُ بلا حدّ.** سحبٌ يتجاوزُ
   *      الحافّةَ يُراكِمُ −٥٠٠٠ بينما المرسومُ واقفٌ عندَ −٦٠٠، فالسحبُ في
   *      الاتّجاهِ المعاكسِ يجبُ أن «يفكَّ» ٤٤٠٠ بكسلٍ قبلَ أن تتحرّكَ الصورةُ
   *      بكسلاً واحداً — أي حركةٌ في اتّجاهٍ واحدٍ بالضبطِ كما وُصِفَتْ.
   *   ٢. **`setOffset` بقيمةٍ محسوبةٍ من `x` الرسمِ يفقدُ الأحداثَ.** يُطلِقُ
   *      المتصفّحُ عدّةَ `pointermove` بينَ رسمَتَين، و`x` ثابتٌ فيها كلِّها بينما
   *      `drag.current` يتقدّم — فكلُّ نداءٍ **يدوسُ** سابقَه بدلَ أن يضيفَ إليه،
   *      فتتحرّكُ الصورةُ بآخرِ فرقٍ وحدَه وتبدو ثقيلةً لاصقة.
   *
   * والمُحدِّثُ الدالّيُّ يقرأُ الحالةَ التي انتهى إليها ما قبلَه، فيُراكِم.
   */
  const move = (event: React.PointerEvent<HTMLDivElement>) => {
    if (drag.current === null) return;

    const dx = event.clientX - drag.current.x;
    const dy = event.clientY - drag.current.y;

    drag.current = { x: event.clientX, y: event.clientY };

    setOffset((previous) => ({
      x: clampOffset(previous.x + dx, frame.naturalWidth, scale, VIEW),
      y: clampOffset(previous.y + dy, frame.naturalHeight, scale, VIEW),
    }));
  };

  /**
   * التكبيرُ يُعيدُ تقييدَ الإزاحةِ بالمقياسِ الجديد.
   *
   * ⚠️ بدونِه يبقى المخزَّنُ صالحاً للمقياسِ القديمِ وحدَه: تكبيرٌ ثمّ سحبٌ إلى
   * الحافّةِ ثمّ تصغيرٌ يترُكُ قيمةً خارجَ الحدِّ الجديد، فيقيّدُها الرسمُ بينما
   * يبدأُ السحبُ التالي منها — وهو الاتّجاهُ الواحدُ عينُه، عائداً من بابٍ آخر.
   *
   * ⚠️ ولاحظْ أنّ المحورَ الأضيقَ لا يتحرّكُ عندَ أدنى تكبيرٍ **وهذا صحيح**:
   * `coverScale` يجعلُ الصورةَ تغطّي النافذةَ بالضبطِ في أحدِ المحورَينِ وتفيضُ في
   * الآخر، فلا فُسحةَ في الأوّلِ إطلاقاً. التكبيرُ هو ما يفتحُها.
   */
  const rezoom = (next: number) => {
    const zoomed = clampZoom(next);
    const zoomedScale = coverScale(frame) * zoomed;

    setZoom(zoomed);
    setOffset((previous) => ({
      x: clampOffset(previous.x, frame.naturalWidth, zoomedScale, VIEW),
      y: clampOffset(previous.y, frame.naturalHeight, zoomedScale, VIEW),
    }));
  };

  return (
    <div className="space-y-4">
      <div
        className="relative mx-auto touch-none overflow-hidden rounded-full bg-primary-soft"
        style={{ width: VIEW, height: VIEW }}
        onPointerDown={(event) => {
          // ⚠️ `setPointerCapture` وإلّا انقطعَ السحبُ لحظةَ خروجِ المؤشِّرِ من
          // الدائرة — وهو ما يفعلُه كلُّ من يسحبُ صورةً إلى حافّتِها.
          event.currentTarget.setPointerCapture?.(event.pointerId);
          drag.current = { x: event.clientX, y: event.clientY };
        }}
        onPointerMove={move}
        onPointerUp={() => {
          drag.current = null;
        }}
        onPointerCancel={() => {
          drag.current = null;
        }}
      >
        {/*
          ⚠️ مثبَّتةٌ عند `left: 0` الفيزيائيِّ لا بالتدفّقِ الطبيعيّ — وهذا خرقٌ
          مقصودٌ لقاعدةِ «الخصائصُ المنطقيّةُ دائماً» في هذا الموضعِ وحدَه.
          الصفحةُ `dir="rtl"`، وصورةٌ أعرضُ من حاويتِها تبدأُ في تدفّقِ RTL من
          الحافّةِ **اليمنى** وتفيضُ يساراً — مقيسٌ في المتصفّحِ ٢٠٢٦-٠٩-٠٦:
          `overflowLeft: 130, overflowRight: 0`. بينما `translate` فيزيائيٌّ
          دائماً، وكلُّ حسابِ `clampOffset` مبنيٌّ على أنّ الصفرَ هو الحافّةُ
          اليسرى. فالنتيجةُ أنّ المدى المسموحَ كلَّه (٠ إلى −١٣٠) يدفعُ الصورةَ
          إلى الفراغِ بدلَ أن يكشفَ بقيّتَها: **حركةٌ في اتّجاهٍ واحدٍ لا غير**،
          وهو نصفُ بلاغِ المستخدِمِ الثاني.
          والتثبيتُ يجعلُ التخطيطَ مستقلّاً عن الاتّجاه، فيبقى حسابٌ واحدٌ لا
          حسابانِ يفترقان.
        */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={image.src}
          alt=""
          draggable={false}
          className="max-w-none select-none"
          style={{
            position: "absolute",
            left: 0,
            top: 0,
            width: image.naturalWidth * scale,
            height: image.naturalHeight * scale,
            transform: `translate(${x}px, ${y}px)`,
          }}
        />
      </div>

      <p className="text-center text-xs text-ink-muted">
        اسحب الصورة لتضبط موضع الوجه، وكبّرها بالشريط.
      </p>

      <label className="block">
        <span className="mb-1 block text-sm text-ink-muted">التكبير</span>
        <input
          type="range"
          min={MIN_ZOOM}
          max={MAX_ZOOM}
          step={0.05}
          value={zoom}
          disabled={busy}
          onChange={(event) => rezoom(Number(event.target.value))}
          className="w-full accent-primary"
        />
      </label>

      <div className="flex gap-2">
        <Button
          type="button"
          loading={busy}
          loadingLabel="جارٍ الحفظ…"
          onClick={() => {
            exportCrop(image, sourceRect(frame, { zoom, offsetX: x, offsetY: y }))
              .then(onCrop)
              .catch((error: unknown) =>
                onError(
                  error instanceof Error ? error.message : "تعذّر تجهيز الصورة.",
                ),
              );
          }}
        >
          احفظ الصورة
        </Button>

        <Button type="button" variant="ghost" disabled={busy} onClick={onCancel}>
          إلغاء
        </Button>
      </div>
    </div>
  );
}
