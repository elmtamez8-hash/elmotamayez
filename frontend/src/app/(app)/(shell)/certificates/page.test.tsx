import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import CertificatesPage from "./page";

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

const certificate = {
  uuid: "u1",
  certificate_number: "CERT-2026-C9RPWNCD",
  verification_code: "CODE1",
  issue_reason: "course_completed",
  issued_at: "2026-05-14T10:22:00Z",
  course_title: "أساسيات الجبر",
  student_name: "كريم محمود",
};

async function open() {
  return act(async () => {
    render(<CertificatesPage />);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("CertificatesPage", () => {
  it("carries every fact the list payload actually holds", async () => {
    get.mockResolvedValue({ data: [certificate] });

    await open();

    expect(screen.getByText("أساسيات الجبر")).toBeDefined();
    expect(screen.getByText("CERT-2026-C9RPWNCD")).toBeDefined();
    // Translated through `labels.ts`, the one map the public page also reads —
    // two spellings of a status is how one screen says «إتمام الكورس» and the
    // next says `course_completed`.
    expect(screen.getByText("إتمام الكورس")).toBeDefined();
    // ⚠️ The name FROZEN on the certificate, not the account's name today.
    expect(screen.getByText("كريم محمود")).toBeDefined();
  });

  it("links each card at its own verification code", async () => {
    get.mockResolvedValue({ data: [certificate] });

    await open();

    expect(screen.getByRole("link", { name: /افتح الشهادة/ }).getAttribute("href")).toBe(
      "/certificates/verify/CODE1",
    );
  });

  it("staggers the cards without letting a long list arrive late", async () => {
    get.mockResolvedValue({
      data: Array.from({ length: 12 }, (_, index) => ({
        ...certificate,
        uuid: `u${index}`,
        verification_code: `CODE${index}`,
      })),
    });

    await open();

    const cards = [...document.querySelectorAll("article")];

    expect(cards).toHaveLength(12);
    expect(cards[0].style.animationDelay).toBe("0ms");
    // ⚠️ CAPPED. Uncapped, the twelfth card would arrive most of a second after
    // the first — an entrance nobody asked to wait for. `globals.css` zeroes both
    // the duration and the delay under `prefers-reduced-motion`, so this costs a
    // reader who asked for less motion nothing at all.
    expect(cards[11].style.animationDelay).toBe("420ms");
  });

  it("offers a way forward when there is nothing yet", async () => {
    get.mockResolvedValue({ data: [] });

    await open();

    expect(screen.getByText("لم تحصل على شهادة بعد")).toBeDefined();
    expect(screen.queryByRole("article")).toBeNull();
  });

  it("treats a failed read as an error state, never an empty one", async () => {
    get.mockRejectedValue(new Error("network"));

    await open();

    expect(screen.queryByText("لم تحصل على شهادة بعد")).toBeNull();
    expect(screen.queryByText(/network/)).toBeNull();
  });
});
