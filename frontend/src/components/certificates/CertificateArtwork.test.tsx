import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CertificateArtwork } from "./CertificateArtwork";
import { FIELD_KEYS, type DesignPayload } from "@/lib/certificate-design";

/*
| ⚠️ `jsdom` لا يخطّطُ ولا يحملُ صوراً: كلُّ `clientWidth` صفر، ولا `ResizeObserver`،
| ولا `naturalWidth`. فما يُقاسُ هنا هو **ما رُسِم** — الحقولُ الستّةُ وحمولةُ
| الرمزِ وحالةُ فشلِ الصورة — أمّا المقاساتُ نفسُها فتُقاسُ في
| `lib/certificate-design.test.ts`، وهو السببُ الذي من أجلِه فُصِلت.
*/

const design: DesignPayload = {
  image_url: "/certificate-templates/classic.webp",
  boxes: {
    student: { x: 0.29, y: 0.41, w: 0.42, h: 0.055, align: "center", max_font: 0.042, min_font: 0.02, color: "certificate-ink" },
    subject: { x: 0.29, y: 0.468, w: 0.42, h: 0.028, align: "center", max_font: 0.022, min_font: 0.013, color: "certificate-ink" },
    teacher: { x: 0.185, y: 0.768, w: 0.145, h: 0.026, align: "center", max_font: 0.02, min_font: 0.012, color: "certificate-ink" },
    date: { x: 0.365, y: 0.768, w: 0.135, h: 0.026, align: "center", max_font: 0.02, min_font: 0.012, color: "certificate-ink" },
    number: { x: 0.54, y: 0.768, w: 0.145, h: 0.026, align: "center", max_font: 0.02, min_font: 0.012, color: "certificate-ink" },
    qr: { x: 0.757, y: 0.695, w: 0.063, h: 0.089 },
  },
};

const values = {
  student: "كريم محمود",
  subject: "الرياضيات",
  teacher: "سامي عبد الله",
  date: "١٤ مايو ٢٠٢٦",
  number: "CERT-2026-A1B2C3D4",
  verifyUrl: "https://mteatch.test/certificates/verify/ABC123",
};

describe("CertificateArtwork", () => {
  it("draws all six fields", () => {
    const { container } = render(<CertificateArtwork design={design} values={values} />);

    for (const key of FIELD_KEYS) {
      expect(container.querySelector(`[data-field="${key}"]`)).not.toBeNull();
    }

    expect(screen.getByText("كريم محمود")).toBeDefined();
    expect(screen.getByText("الرياضيات")).toBeDefined();
    expect(screen.getByText("سامي عبد الله")).toBeDefined();
    expect(screen.getByText("١٤ مايو ٢٠٢٦")).toBeDefined();
    expect(screen.getByText("CERT-2026-A1B2C3D4")).toBeDefined();
  });

  it("gives every field a non-zero font size even before the frame is measured", () => {
    const { container } = render(<CertificateArtwork design={design} values={values} />);

    // A nominal width is the whole reason this holds: with 0 the sheet renders
    // and every glyph is 0px tall, which no snapshot and no query would notice.
    const box = container.querySelector<HTMLElement>('[data-field="student"]');

    expect(box).not.toBeNull();
    expect(Number.parseFloat(box!.style.fontSize)).toBeGreaterThan(0);
  });

  it("encodes the absolute verify URL in the code", () => {
    render(<CertificateArtwork design={design} values={values} />);

    const code = screen.getByLabelText(`رمز التحقّق: ${values.verifyUrl}`);

    expect(code).toBeDefined();
    // A code with no modules is a blank square that scans as nothing.
    expect(code.querySelectorAll("rect").length).toBeGreaterThan(10);
  });

  it("keeps the facts readable when the artwork fails to load", () => {
    const { container } = render(<CertificateArtwork design={design} values={values} />);

    const image = container.querySelector("img");

    expect(image).not.toBeNull();

    fireEvent.error(image!);

    expect(container.querySelector("img")).toBeNull();
    expect(screen.getByText("كريم محمود")).toBeDefined();
    expect(screen.getByText("CERT-2026-A1B2C3D4")).toBeDefined();
  });

  it("draws no box for a fact the certificate does not carry", () => {
    const { container } = render(
      <CertificateArtwork design={design} values={{ ...values, teacher: "" }} />,
    );

    expect(container.querySelector('[data-field="teacher"]')).toBeNull();
    expect(container.querySelector('[data-field="student"]')).not.toBeNull();
  });

  it("falls back to the certificate ink rather than painting nothing", () => {
    const broken: DesignPayload = {
      ...design,
      boxes: { ...design.boxes, student: { ...design.boxes.student, color: "success-soft" } },
    };

    const { container } = render(<CertificateArtwork design={broken} values={values} />);
    const box = container.querySelector<HTMLElement>('[data-field="student"]');

    expect(box!.style.color).toContain("--color-certificate-ink");
  });
});
