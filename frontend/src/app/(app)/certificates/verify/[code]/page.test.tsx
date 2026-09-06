import { Suspense } from "react";
import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import VerifyCertificatePage from "./page";
import type { DesignPayload } from "@/lib/certificate-design";

/*
| ⚠️ `use(params)` يُعلِّقُ المكوّن، فـ`render` عارياً يقفُ على بديلِ `Suspense` إلى
| الأبد: كلُّ حالةٍ تسقطُ برسالةٍ عن نصٍّ مفقودٍ في صفحةٍ **لم تُرسَمْ أصلاً**، وهي
| رسالةٌ تُقرأُ كعطبٍ في المنتَجِ لا كعطبٍ في الاختبار. الشكلُ الوحيدُ الذي يعملُ هو
| `await act(async () => render(<Suspense …>))`.
*/

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

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

const certificate = {
  certificate_number: "CERT-2026-A1B2C3D4",
  verification_code: "ABC123",
  issue_reason: "course_completed",
  issued_at: "2026-05-14T10:22:00Z",
  course_title: "أساسيات الجبر",
  student_name: "كريم محمود",
  teacher_name: "سامي عبد الله",
  subject_name: "الرياضيات",
  design,
};

async function open() {
  return act(async () => {
    render(
      <Suspense fallback={null}>
        <VerifyCertificatePage params={Promise.resolve({ code: "ABC123" })} />
      </Suspense>,
    );
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("VerifyCertificatePage", () => {
  it("draws the certificate and repeats its facts as text", async () => {
    get.mockResolvedValue({ valid: true, certificate });

    await open();

    expect(get).toHaveBeenCalledWith("/certificates/verify/ABC123");

    // The verdict, above the artwork and outside it.
    expect(screen.getByText("الشهادة موثّقة")).toBeDefined();

    // The artwork itself.
    expect(screen.getByRole("img", { name: "شهادة كريم محمود" })).toBeDefined();

    // And the same facts as text — the page must survive with no images at all.
    expect(screen.getAllByText("كريم محمود").length).toBeGreaterThan(1);
    expect(screen.getAllByText("سامي عبد الله").length).toBeGreaterThan(1);
    expect(screen.getAllByText("الرياضيات").length).toBeGreaterThan(1);
    expect(screen.getAllByText("CERT-2026-A1B2C3D4").length).toBeGreaterThan(1);
    expect(screen.getByText("إتمام الكورس")).toBeDefined();
  });

  it("points the code at this page's own absolute address", async () => {
    get.mockResolvedValue({ valid: true, certificate });

    await open();

    expect(screen.getByLabelText(`رمز التحقّق: ${window.location.href}`)).toBeDefined();
  });

  it("shows a refusal with no artwork and no name for an unknown code", async () => {
    get.mockResolvedValue({ valid: false });

    await open();

    expect(screen.getByRole("alert")).toBeDefined();
    expect(screen.getByText("شهادة غير صالحة")).toBeDefined();
    expect(screen.queryByText("كريم محمود")).toBeNull();
    expect(screen.queryByRole("img", { name: /شهادة/ })).toBeNull();
    expect(document.querySelector("img")).toBeNull();
  });

  it("treats a failed lookup as an unconfirmed certificate, never a raw error", async () => {
    get.mockRejectedValue(new Error("network"));

    await open();

    expect(screen.getByText("شهادة غير صالحة")).toBeDefined();
    expect(screen.queryByText(/network/)).toBeNull();
  });

  it("falls back to the course title when the certificate carries no subject", async () => {
    get.mockResolvedValue({
      valid: true,
      certificate: { ...certificate, subject_name: null },
    });

    await open();

    expect(screen.getAllByText("أساسيات الجبر").length).toBeGreaterThan(0);
  });
});
