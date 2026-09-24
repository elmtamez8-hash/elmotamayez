import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| «حذف أو تعطيل» deleted an unused question for good on one press. It asks now.
*/
const question = vi.fn();
const remove = vi.fn();

vi.mock("@/lib/bank", () => ({
  bank: {
    question: (uuid: string) => question(uuid),
    remove: (uuid: string) => remove(uuid),
  },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));
vi.mock("@/components/bank/QuestionForm", () => ({ QuestionForm: () => null }));

const { default: EditBankQuestionPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  question.mockResolvedValue({ data: { uuid: "q-1", usage_count: 0, is_active: true } });
});

async function openPage() {
  await act(async () => {
    render(<EditBankQuestionPage params={Promise.resolve({ uuid: "q-1" })} />);
  });
}

describe("deleting a bank question", () => {
  it("asks first, and removes nothing on the first press", async () => {
    await openPage();

    fireEvent.click(screen.getByRole("button", { name: "حذف أو تعطيل" }));

    expect(screen.getByText("حذف السؤال")).toBeTruthy();
    expect(remove).not.toHaveBeenCalled();
  });

  it("confirming removes exactly once", async () => {
    remove.mockResolvedValue({ deleted: true });
    await openPage();

    fireEvent.click(screen.getByRole("button", { name: "حذف أو تعطيل" }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احذف أو عطِّل" }));
    });

    expect(remove).toHaveBeenCalledTimes(1);
    expect(remove).toHaveBeenCalledWith("q-1");
  });
});
