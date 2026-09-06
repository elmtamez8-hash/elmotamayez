import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { TemplateGallery } from "./TemplateGallery";
import type { CertificateDesignCard } from "@/lib/certificate-designs";
import type { FieldBox, FieldKey } from "@/lib/certificate-design";

const box = (x: number, y: number): FieldBox => ({
  x,
  y,
  w: 0.3,
  h: 0.05,
  align: "center",
  max_font: 0.04,
  min_font: 0.02,
  color: "certificate-ink",
});

const boxes: Record<FieldKey, FieldBox> = {
  student: box(0.29, 0.41),
  subject: box(0.29, 0.47),
  teacher: box(0.18, 0.77),
  date: box(0.36, 0.77),
  number: box(0.54, 0.77),
  qr: { x: 0.75, y: 0.69, w: 0.06, h: 0.09 },
};

const shipped: CertificateDesignCard = {
  uuid: null,
  system_key: "classic",
  name: "كلاسيكي",
  image_url: "/certificate-templates/classic.webp",
  source: "system",
  is_selected: false,
  is_ready: true,
  boxes,
};

const selected: CertificateDesignCard = {
  ...shipped,
  uuid: "aaa",
  system_key: "students",
  name: "طلّاب",
  image_url: "/certificate-templates/students.webp",
  is_selected: true,
};

const unready: CertificateDesignCard = {
  uuid: "bbb",
  system_key: null,
  name: "تصميم المركز",
  image_url: "/storage/certificate-designs/bbb.webp",
  source: "uploaded",
  is_selected: false,
  is_ready: false,
  boxes: null,
};

describe("TemplateGallery", () => {
  it("draws each ready design rather than showing a bare thumbnail", () => {
    const { container } = render(
      <TemplateGallery designs={[shipped, selected]} onSelect={vi.fn()} />,
    );

    // FR-013: the preview is the real component, so the fields are actually
    // placed — a gallery of images would hide the one thing that differs.
    expect(container.querySelectorAll('[data-field="student"]').length).toBe(2);
    expect(container.querySelectorAll('[data-field="qr"]').length).toBe(2);
  });

  it("previews with a name long enough to show the shrinking", () => {
    const { container } = render(<TemplateGallery designs={[shipped]} onSelect={vi.fn()} />);

    const name = container.querySelector('[data-field="student"]');

    expect(name?.textContent?.length ?? 0).toBeGreaterThan(20);
  });

  it("marks the selected design and offers no second selection of it", () => {
    render(<TemplateGallery designs={[selected]} onSelect={vi.fn()} />);

    expect(screen.getByText("المعتمد الآن")).toBeDefined();
    expect(screen.getByRole("button", { name: "معتمد" }).hasAttribute("disabled")).toBe(true);
  });

  it("refuses to select an uploaded design that has no positions yet", () => {
    const onSelect = vi.fn();
    const { container } = render(<TemplateGallery designs={[unready]} onSelect={onSelect} />);

    expect(screen.getByText("يحتاج ضبطاً")).toBeDefined();
    expect(screen.getByRole("button", { name: "اعتمد هذا القالب" }).hasAttribute("disabled")).toBe(true);

    // ⚠️ And it is drawn as a bare image, never with another design's boxes: a
    // preview correct for artwork the teacher is not looking at is worse than none.
    expect(container.querySelector('[data-field="student"]')).toBeNull();
  });

  it("hands the whole card back when one is chosen", () => {
    const onSelect = vi.fn();

    render(<TemplateGallery designs={[shipped]} onSelect={onSelect} />);

    fireEvent.click(screen.getByRole("button", { name: "اعتمد هذا القالب" }));

    expect(onSelect).toHaveBeenCalledWith(shipped);
  });
});
