import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AssistantsPage from "./page";
import type { AssistantAssignment } from "@/lib/assistants";

/*
| The team screen after its redesign. What these assert is what the layout must
| not lose: the one door (a LINK to the members screen, never a form here), the
| scope written in words, the editor that opens in place, the withdrawn still
| listed, and an arrival that never makes a long team wait.
*/

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: {
    get: (path: string) => get(path),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

function assignment(overrides: Partial<AssistantAssignment> = {}): AssistantAssignment {
  return {
    uuid: "a1",
    assistant: { uuid: "u1", name: "سارة المصحّحة" },
    workspace: null,
    is_confined: false,
    courses: [],
    revoked_at: null,
    ...overrides,
  };
}

function serve(team: AssistantAssignment[]) {
  get.mockImplementation((path: string) => {
    if (path === "/manage/assistants") return Promise.resolve({ data: team });
    if (path === "/courses?per_page=200") {
      return Promise.resolve({
        data: [
          { uuid: "c1", title: "الرياضيات" },
          { uuid: "c2", title: "الفيزياء" },
        ],
      });
    }

    return Promise.reject(new Error(`unexpected read ${path}`));
  });
}

async function open() {
  return act(async () => {
    render(<AssistantsPage />);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("AssistantsPage", () => {
  it("points the primary action at the members screen, and builds no invite form here", async () => {
    serve([assignment()]);

    await open();

    expect(screen.getByRole("link", { name: "دعوة مساعد" }).getAttribute("href")).toBe("/members");
    expect(screen.queryByRole("textbox")).toBeNull();
  });

  it("keeps the header while the team loads", async () => {
    get.mockReturnValue(new Promise(() => undefined));

    await open();

    expect(screen.getByText("فريق المساعدين")).toBeDefined();
    expect(screen.getByRole("status", { name: "جارٍ التحميل" })).toBeDefined();
  });

  it("says who is on the team, counted, and what each one works on", async () => {
    serve([
      assignment(),
      assignment({
        uuid: "a2",
        assistant: { uuid: "u2", name: "خالد" },
        is_confined: true,
        courses: [
          { uuid: "c1", title: "الرياضيات" },
          { uuid: "c2", title: "الفيزياء" },
        ],
      }),
    ]);

    await open();

    expect(screen.getByText("مساعدان")).toBeDefined();
    expect(screen.getByText("كل الكورسات")).toBeDefined();
    // After «على» the dual is genitive — «كورسين», never the nominative «كورسان».
    expect(screen.getByText("مقصور على كورسين")).toBeDefined();
    expect(
      within(screen.getByRole("list", { name: "كورسات هذا المساعد" })).getAllByRole("listitem"),
    ).toHaveLength(2);
  });

  it("opens the course editor in place and says so to assistive technology", async () => {
    serve([assignment()]);

    await open();

    const toggle = screen.getByRole("button", { name: /تعديل الكورسات/ });
    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByRole("button", { name: "حفظ النطاق" })).toBeNull();

    fireEvent.click(toggle);

    expect(screen.getByRole("button", { name: /إخفاء الكورسات/ }).getAttribute("aria-expanded")).toBe(
      "true",
    );
    expect(document.getElementById(toggle.getAttribute("aria-controls") ?? "")).not.toBeNull();
    expect(screen.getByRole("button", { name: "حفظ النطاق" })).toBeDefined();
  });

  it("offers the invitation from an empty team, and still lists those who left", async () => {
    serve([assignment({ uuid: "old", assistant: { uuid: "u9", name: "منى" }, revoked_at: "2026-03-01T00:00:00Z" })]);

    await open();

    expect(screen.getByText("لا مساعدين بعد")).toBeDefined();
    expect(screen.getByRole("link", { name: "ادعُ أوّل مساعد" }).getAttribute("href")).toBe("/members");
    expect(screen.getByText("منى")).toBeDefined();
    expect(screen.getByText("أُنهيت المهمة")).toBeDefined();
  });

  it("staggers the cards without letting a long team arrive late", async () => {
    serve(
      Array.from({ length: 10 }, (_, index) =>
        assignment({ uuid: `a${index}`, assistant: { uuid: `u${index}`, name: `مساعد ${index}` } }),
      ),
    );

    await open();

    const items = screen
      .getAllByRole("listitem")
      .filter((item) => item.classList.contains("banner-rise"));

    expect(items).toHaveLength(10);
    expect(items[0].style.animationDelay).toBe("0ms");
    expect(items[9].style.animationDelay).toBe("270ms");
  });

  it("shows the retry, never the raw failure, when the team cannot be read", async () => {
    get.mockRejectedValue(new Error("SQLSTATE[HY000] boom"));

    await open();

    expect(screen.getByRole("alert")).toBeDefined();
    expect(screen.queryByText(/SQLSTATE/)).toBeNull();
    expect(screen.getByRole("link", { name: "دعوة مساعد" })).toBeDefined();
  });
});
