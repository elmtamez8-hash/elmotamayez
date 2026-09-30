import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| `/manage/bank/import` inherits the sidebar's `bank.view` answer, and every read
| on it asks `questions.manage` (`ImportController`). Without the gate a reader of
| the bank got «تعذّر تحميل البيانات» — a 403 dressed as a network fault — and
| the request that earned it.
*/
const imports = vi.fn();

vi.mock("@/lib/bank", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/bank")>()),
  bank: { imports: () => imports(), startImport: vi.fn() },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));

let mockUser: { permissions: string[] } = { permissions: ["bank.view"] };

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: ImportPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  imports.mockResolvedValue({ data: [] });
});

describe("the import screen", () => {
  it("refuses a reader of the bank in words, and asks the server nothing", async () => {
    mockUser = { permissions: ["bank.view"] };

    await act(async () => {
      render(<ImportPage />);
    });

    expect(screen.getByText("هذه الصفحة ليست لك")).toBeTruthy();
    expect(screen.getByText("إضافة الأسئلة إلى البنك واستيرادها يتولّاه المدرّس.")).toBeTruthy();
    expect(imports).not.toHaveBeenCalled();
  });

  it("opens for a reader who may write in the bank", async () => {
    mockUser = { permissions: ["bank.view", "questions.manage"] };

    await act(async () => {
      render(<ImportPage />);
    });

    expect(screen.queryByText("هذه الصفحة ليست لك")).toBeNull();
    expect(imports).toHaveBeenCalledTimes(1);
  });
});
