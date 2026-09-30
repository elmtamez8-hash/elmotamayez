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
vi.mock("@/components/bank/QuestionForm", () => ({
  QuestionForm: ({ readOnly }: { readOnly?: boolean }) => <p>{readOnly ? "نموذج للقراءة" : "نموذج للتعديل"}</p>,
}));
vi.mock("@/components/bank/RubricEditor", () => ({ RubricEditor: () => <p>محرّر المعايير</p> }));
let mockUser: { permissions: string[] } = { permissions: ["bank.view", "questions.manage"] };

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: EditBankQuestionPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { permissions: ["bank.view", "questions.manage"] };
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

describe("the rubric editor", () => {
  it("appears on an essay question", async () => {
    question.mockResolvedValue({ data: { uuid: "q-1", type: "essay", usage_count: 0, is_active: true } });
    await openPage();

    expect(screen.getByText("محرّر المعايير")).toBeTruthy();
  });

  it("does not appear on a machine-marked question", async () => {
    question.mockResolvedValue({ data: { uuid: "q-1", type: "mcq", usage_count: 0, is_active: true } });
    await openPage();

    expect(screen.queryByText("محرّر المعايير")).toBeNull();
  });
});

// `QuestionPolicy::view` is `bank.view`; update and delete are `questions.manage`.
describe("a reader of the bank who may not write in it", () => {
  it("reads the question, with no delete and a read-only form", async () => {
    mockUser = { permissions: ["bank.view"] };
    question.mockResolvedValue({ data: { uuid: "q-1", type: "essay", usage_count: 0, is_active: true } });
    await openPage();

    expect(screen.queryByRole("button", { name: "حذف أو تعطيل" })).toBeNull();
    expect(screen.getByText("نموذج للقراءة")).toBeTruthy();
    expect(screen.getByText("للقراءة فقط")).toBeTruthy();
    expect(screen.queryByText("محرّر المعايير")).toBeNull();
  });

  it("gives the writer the editable form", async () => {
    await openPage();

    expect(screen.getByText("نموذج للتعديل")).toBeTruthy();
    expect(screen.queryByText("للقراءة فقط")).toBeNull();
  });
});
