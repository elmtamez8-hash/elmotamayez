"use client";

import { useRef, type PointerEvent as ReactPointerEvent } from "react";

import { CertificateArtwork } from "@/components/certificates/CertificateArtwork";
import {
  FIELD_KEYS,
  clampBox,
  type CertificateValues,
  type FieldBox,
  type FieldKey,
} from "@/lib/certificate-design";

/** What each box is called, for the handle's accessible name. */
const LABELS: Record<FieldKey, string> = {
  student: "اسم الطالب",
  subject: "المادة",
  teacher: "اسم المدرّس",
  date: "تاريخ المنح",
  number: "رقم الشهادة",
  qr: "رمز التحقّق",
};

export type Boxes = Record<FieldKey, FieldBox>;

export interface FieldBoxEditorProps {
  imageUrl: string;
  boxes: Boxes;
  values: CertificateValues;
  /**
   * ⚠️ A FUNCTIONAL UPDATER, NEVER A VALUE. Several `pointermove` events land
   * between two renders, so an offset applied to the `boxes` PROP is applied to a
   * position two frames old — the box lags the finger and then jumps back. The
   * `AvatarCropper` lesson.
   */
  onChange: (update: (previous: Boxes) => Boxes) => void;
}

/**
 * Dragging the six fields into place ON THE ARTWORK ITSELF (`FR-019`).
 *
 * ⚠️ THE PREVIEW IS `CertificateArtwork`, the same component the public page
 * draws with. A preview rendered by a second code path is the worst thing a
 * position editor can do to a teacher: they would adjust one drawing and publish
 * another.
 *
 * ⚠️ AND THE CLAMPING IS AT THE STORE, NOT AT THE DRAW (`FR-020`). A box saved
 * outside the image and repaired by every reader is a box one reader will forget
 * to repair — and that reader is a parent's phone.
 */
export function FieldBoxEditor({ imageUrl, boxes, values, onChange }: FieldBoxEditorProps) {
  const frame = useRef<HTMLDivElement>(null);
  const drag = useRef<{ key: FieldKey; x: number; y: number } | null>(null);

  function down(event: ReactPointerEvent<HTMLButtonElement>, key: FieldKey) {
    drag.current = { key, x: event.clientX, y: event.clientY };

    /*
      Pointer capture keeps the drag alive when the finger leaves the handle —
      without it a quick movement drops the box halfway. jsdom implements none of
      the pointer-capture API, so it is guarded rather than assumed: the same
      shape as the `getContext` try/catch in the geometry module.
    */
    try {
      event.currentTarget.setPointerCapture(event.pointerId);
    } catch {
      // No capture available; dragging still works while the pointer is over it.
    }
  }

  function move(event: ReactPointerEvent<HTMLButtonElement>) {
    const state = drag.current;
    const rect = frame.current?.getBoundingClientRect();

    if (state === null || rect === undefined || rect.width === 0 || rect.height === 0) return;

    // Physical pixels against the frame's own box — never a logical property.
    // The page is RTL, the artwork's geometry is not: `x` is a fraction from the
    // image's left edge in every direction the document happens to read.
    const dx = (event.clientX - state.x) / rect.width;
    const dy = (event.clientY - state.y) / rect.height;

    drag.current = { key: state.key, x: event.clientX, y: event.clientY };

    onChange((previous) => {
      const box = previous[state.key];

      return {
        ...previous,
        [state.key]: clampBox({ ...box, x: box.x + dx, y: box.y + dy }),
      };
    });
  }

  function up(event: ReactPointerEvent<HTMLButtonElement>) {
    drag.current = null;

    try {
      event.currentTarget.releasePointerCapture(event.pointerId);
    } catch {
      // Nothing was captured.
    }
  }

  return (
    <div ref={frame} className="relative touch-none select-none">
      <CertificateArtwork
        design={{ image_url: imageUrl, boxes }}
        values={values}
        ariaLabel="معاينة حيّة للشهادة أثناء ضبط المواضع"
      />

      {FIELD_KEYS.map((key) => {
        const box = boxes[key];

        if (box === undefined) return null;

        return (
          <button
            key={key}
            type="button"
            data-handle={key}
            aria-label={`اسحب موضع ${LABELS[key]}`}
            onPointerDown={(event) => down(event, key)}
            onPointerMove={move}
            onPointerUp={up}
            onPointerCancel={up}
            className="absolute cursor-move rounded border-2 border-dashed border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            style={{
              left: `${box.x * 100}%`,
              top: `${box.y * 100}%`,
              width: `${box.w * 100}%`,
              height: `${box.h * 100}%`,
            }}
          />
        );
      })}
    </div>
  );
}
