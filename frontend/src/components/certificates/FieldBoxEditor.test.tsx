import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { FieldBoxEditor, type Boxes } from "./FieldBoxEditor";
import type { CertificateValues } from "@/lib/certificate-design";

const FRAME_WIDTH = 1000;
const FRAME_HEIGHT = 700;

const boxes: Boxes = {
  student: { x: 0.4, y: 0.4, w: 0.2, h: 0.05, align: "center", max_font: 0.04, min_font: 0.02, color: "certificate-ink" },
  subject: { x: 0.4, y: 0.5, w: 0.2, h: 0.03, align: "center", max_font: 0.02, min_font: 0.01, color: "certificate-ink" },
  teacher: { x: 0.2, y: 0.8, w: 0.15, h: 0.03, align: "center", max_font: 0.02, min_font: 0.01, color: "certificate-ink" },
  date: { x: 0.4, y: 0.8, w: 0.15, h: 0.03, align: "center", max_font: 0.02, min_font: 0.01, color: "certificate-ink" },
  number: { x: 0.6, y: 0.8, w: 0.15, h: 0.03, align: "center", max_font: 0.016, min_font: 0.01, color: "certificate-ink" },
  qr: { x: 0.75, y: 0.7, w: 0.06, h: 0.09 },
};

const values: CertificateValues = {
  student: "كريم محمود",
  subject: "الرياضيات",
  teacher: "سامي عبد الله",
  date: "١٤ مايو ٢٠٢٦",
  number: "CERT-2026-A1B2C3D4",
  verifyUrl: "https://example.test/certificates/verify/ABC",
};

/*
| ⚠️ jsdom DOES NO LAYOUT, so every rect is zeros and a drag would divide by zero
| and move nothing — the test would pass over a component that never worked. The
| frame is given real dimensions here, which is the only way this behaviour is
| measurable at all.
*/
let rect: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  rect = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({
    x: 0,
    y: 0,
    left: 0,
    top: 0,
    right: FRAME_WIDTH,
    bottom: FRAME_HEIGHT,
    width: FRAME_WIDTH,
    height: FRAME_HEIGHT,
    toJSON: () => ({}),
  } as DOMRect);
});

afterEach(() => {
  rect.mockRestore();
});

/** Applies whatever the editor asked for to a starting set, as the page does. */
function apply(onChange: ReturnType<typeof vi.fn>, start: Boxes): Boxes {
  return onChange.mock.calls.reduce<Boxes>((current, [update]) => update(current), start);
}

function drag(handle: HTMLElement, dx: number, dy: number) {
  fireEvent.pointerDown(handle, { clientX: 500, clientY: 350, pointerId: 1 });
  fireEvent.pointerMove(handle, { clientX: 500 + dx, clientY: 350 + dy, pointerId: 1 });
  fireEvent.pointerUp(handle, { clientX: 500 + dx, clientY: 350 + dy, pointerId: 1 });
}

describe("FieldBoxEditor", () => {
  it("moves a box in both directions", () => {
    const onChange = vi.fn();

    render(<FieldBoxEditor imageUrl="/certificate-templates/classic.webp" boxes={boxes} values={values} onChange={onChange} />);

    drag(screen.getByLabelText("اسحب موضع اسم الطالب"), 100, 70);

    const moved = apply(onChange, boxes);

    // 100px of a 1000px frame is a tenth of the image; 70 of 700 likewise.
    expect(moved.student.x).toBeCloseTo(0.5, 5);
    expect(moved.student.y).toBeCloseTo(0.5, 5);

    // And backwards, from the position it now holds.
    onChange.mockClear();
    drag(screen.getByLabelText("اسحب موضع اسم الطالب"), -200, -140);

    const back = apply(onChange, moved);

    expect(back.student.x).toBeCloseTo(0.3, 5);
    expect(back.student.y).toBeCloseTo(0.3, 5);
  });

  it("clamps at the edge instead of leaving the image", () => {
    const onChange = vi.fn();

    render(<FieldBoxEditor imageUrl="/certificate-templates/classic.webp" boxes={boxes} values={values} onChange={onChange} />);

    // Far past the right edge — `x + w` may not exceed 1 (`FR-020`).
    drag(screen.getByLabelText("اسحب موضع رمز التحقّق"), 5000, 5000);

    const moved = apply(onChange, boxes);

    expect(moved.qr.x + moved.qr.w).toBeLessThanOrEqual(1);
    expect(moved.qr.y + moved.qr.h).toBeLessThanOrEqual(1);
    expect(moved.qr.x).toBeCloseTo(1 - moved.qr.w, 5);
  });

  it("accumulates several moves rather than answering from a stale position", () => {
    const onChange = vi.fn();

    render(<FieldBoxEditor imageUrl="/certificate-templates/classic.webp" boxes={boxes} values={values} onChange={onChange} />);

    const handle = screen.getByLabelText("اسحب موضع اسم المدرّس");

    // ⚠️ Three `pointermove`s with no re-render between them — the real shape of a
    // drag. An offset applied to the PROP would land the box at the last event's
    // delta alone; the functional updater lands it at the sum.
    fireEvent.pointerDown(handle, { clientX: 200, clientY: 560, pointerId: 1 });
    fireEvent.pointerMove(handle, { clientX: 250, clientY: 560, pointerId: 1 });
    fireEvent.pointerMove(handle, { clientX: 300, clientY: 560, pointerId: 1 });
    fireEvent.pointerMove(handle, { clientX: 350, clientY: 560, pointerId: 1 });
    fireEvent.pointerUp(handle, { clientX: 350, clientY: 560, pointerId: 1 });

    expect(apply(onChange, boxes).teacher.x).toBeCloseTo(0.35, 5);
  });

  it("draws the live preview through the same component the certificate uses", () => {
    const { container } = render(
      <FieldBoxEditor imageUrl="/certificate-templates/classic.webp" boxes={boxes} values={values} onChange={vi.fn()} />,
    );

    expect(container.querySelector('[data-field="student"]')).not.toBeNull();
    expect(container.querySelectorAll("[data-handle]").length).toBe(6);
  });
});
