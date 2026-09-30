import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| بنكُ الأسئلة يقرؤه المساعدُ (`bank.view`) ولا يكتبُ فيه: «سؤال جديد» و«استيراد
| من ملف» يسألانِ `questions.manage`، وقد أخرجَها ٠٠٨ من دورِ المساعد. فالزرّانِ
| يغيبانِ عنه بدلَ أن يفتحا صفحةً يرفضُها الخادمُ عندَ أوّلِ طلب.
*/
const questions = vi.fn();

vi.mock("@/lib/bank", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/bank")>()),
  bank: {
    questions: (filters: unknown) => questions(filters),
    concepts: () => Promise.resolve({ data: [] }),
  },
}));
vi.mock("@/components/bank/ConceptManager", () => ({ ConceptManager: () => <p>إدارة الأفكار</p> }));

let mockUser: { permissions: string[] } = { permissions: ["bank.view", "questions.manage"] };

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: BankPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers({ shouldAdvanceTime: true });
  questions.mockResolvedValue({ data: [] });
  mockUser = { permissions: ["bank.view", "questions.manage"] };
});

async function open() {
  render(<BankPage />);
  // The list is fetched behind a 300ms debounce.
  await act(async () => {
    await vi.advanceTimersByTimeAsync(400);
  });
}

describe("the bank's write doors", () => {
  it("are offered to a reader who may write", async () => {
    await open();

    expect(screen.getByRole("link", { name: "سؤال جديد" })).toBeTruthy();
    expect(screen.getAllByRole("link", { name: "استيراد من ملف" }).length).toBeGreaterThan(0);
    expect(screen.getByText("إدارة الأفكار")).toBeTruthy();
  });

  it("are absent — header and empty state both — for a reader of the bank only", async () => {
    mockUser = { permissions: ["bank.view"] };
    await open();

    expect(screen.queryByRole("link", { name: "سؤال جديد" })).toBeNull();
    expect(screen.queryByRole("link", { name: "استيراد من ملف" })).toBeNull();
    expect(screen.queryByText("إدارة الأفكار")).toBeNull();
  });
});
