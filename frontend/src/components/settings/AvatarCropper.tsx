"use client";

import { useRef, useState } from "react";

import { Button } from "@/components/ui/Button";
import {
  MAX_ZOOM,
  MIN_ZOOM,
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
  const [zoom, setZoom] = useState(MIN_ZOOM);
  const [offset, setOffset] = useState({ x: 0, y: 0 });
  const drag = useRef<{ x: number; y: number } | null>(null);

  const frame = {
    naturalWidth: image.naturalWidth,
    naturalHeight: image.naturalHeight,
    view: VIEW,
  };
  const scale = coverScale(frame) * clampZoom(zoom);

  // مقيَّدةٌ عندَ **الرسمِ** لا عندَ الحفظِ وحدَه: تكبيرٌ ثمّ تصغيرٌ يترُكُ إزاحةً
  // كانت صالحةً وصارتْ خارجَ الحدّ، فتظهرُ حافّةٌ فارغةٌ داخلَ الدائرة.
  const x = clampOffset(offset.x, frame.naturalWidth, scale, VIEW);
  const y = clampOffset(offset.y, frame.naturalHeight, scale, VIEW);

  const move = (event: React.PointerEvent<HTMLDivElement>) => {
    if (drag.current === null) return;

    setOffset({
      x: x + (event.clientX - drag.current.x),
      y: y + (event.clientY - drag.current.y),
    });

    drag.current = { x: event.clientX, y: event.clientY };
  };

  return (
    <div className="space-y-4">
      <div
        className="relative mx-auto touch-none overflow-hidden rounded-full bg-primary-soft"
        style={{ width: VIEW, height: VIEW }}
        onPointerDown={(event) => {
          // ⚠️ `setPointerCapture` وإلّا انقطعَ السحبُ لحظةَ خروجِ المؤشِّرِ من
          // الدائرة — وهو ما يفعلُه كلُّ من يسحبُ صورةً إلى حافّتِها.
          event.currentTarget.setPointerCapture(event.pointerId);
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
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={image.src}
          alt=""
          draggable={false}
          className="max-w-none select-none"
          style={{
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
          onChange={(event) => setZoom(Number(event.target.value))}
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
