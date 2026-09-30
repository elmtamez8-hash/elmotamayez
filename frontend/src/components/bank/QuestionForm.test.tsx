import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

/*
| EXACTLY ONE CORRECT OPTION, AND THE FORM USED TO OFFER CHECKBOXES.
|
| «At least one» reads as generous and is not: the grader compares the correct SET
| against the selected SET, so a question with two correct options can only be scored
| by selecting both — while every answering screen in this product takes ONE answer.
| Nobody can ever be right, and a wrong_pct of 100% reads as the hardest item in the
| bank, which is the number a teacher deletes a good question over.
|
| The server refuses it now, but a form that lets a teacher build the refusal and
| only tells them at save time is a form that wastes their work. The control is a
| real radio: exclusivity comes from the browser, and a screen reader is told
| «radio», not «checkbox» — which would mean "these toggle independently", the one
| thing that must not be said here.
*/

const createConcept = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

// The form asks for the concept list on mount. Mocked at the module rather than at
// `fetch`: what this test is about is a click, and a network stub would make it
// about wiring.
vi.mock("@/lib/bank", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/bank")>();

  return {
    ...actual,
    bank: {
      concepts: () => Promise.resolve({ data: [] }),
      createConcept: (name: string) => createConcept(name),
      create: vi.fn(),
      update: vi.fn(),
    },
  };
});

const { QuestionForm } = await import("./QuestionForm");

/** The «صحيح» marker beside option N (1-based), which is a radio in one group. */
function correctMarker(index: number): HTMLInputElement {
  return screen.getAllByRole("radio", { name: "صحيح" })[index - 1] as HTMLInputElement;
}

describe("QuestionForm", () => {
  it("moves the correct answer instead of adding a second one", async () => {
    const user = userEvent.setup();

    render(<QuestionForm />);

    // The form opens with two options and the first marked correct.
    expect(correctMarker(1).checked).toBe(true);
    expect(correctMarker(2).checked).toBe(false);

    await user.click(correctMarker(2));

    expect(correctMarker(1).checked).toBe(false);
    expect(correctMarker(2).checked).toBe(true);
  });

  /*
   | The exclusivity has to survive a third option arriving, because that is the
   | shape a real question is built in: type the stem, add choices, then mark one.
   */
  it("keeps one answer after another option is added", async () => {
    const user = userEvent.setup();

    render(<QuestionForm />);

    await user.click(screen.getByRole("button", { name: "أضف خياراً" }));
    await user.click(correctMarker(3));

    expect(screen.getAllByRole("radio", { name: "صحيح" })).toHaveLength(3);
    expect(correctMarker(1).checked).toBe(false);
    expect(correctMarker(2).checked).toBe(false);
    expect(correctMarker(3).checked).toBe(true);
  });

  /*
   | ⚠️ A NEW TEACHER'S BANK HAS NO CONCEPTS, AND THE CONCEPT IS A REQUIRED TAG.
   | The picker offered only existing ones and nothing called the create route, so
   | the first question could not be written by hand at all.
   */
  it("lets a teacher with an empty bank create a concept and selects it", async () => {
    createConcept.mockResolvedValue({ data: { uuid: "c-new", name: "المعادلات الخطية" } });

    render(<QuestionForm />);

    // Open without being asked: an empty bank has nothing else to offer.
    const input = await screen.findByLabelText("فكرة جديدة");
    fireEvent.change(input, { target: { value: "  المعادلات الخطية " } });
    fireEvent.click(screen.getByRole("button", { name: "أضف الفكرة" }));

    await waitFor(() => expect((document.getElementById("concept_id") as HTMLSelectElement).value).toBe("c-new"));
    expect(createConcept).toHaveBeenCalledWith("المعادلات الخطية");
    expect(screen.queryByLabelText("فكرة جديدة")).toBeNull();
  });

  it("puts the server's refusal under the field instead of losing it", async () => {
    const { ApiError } = await import("@/lib/api");
    createConcept.mockRejectedValue(
      new ApiError("invalid", 422, { message: "invalid", errors: { name: ["الاسم مستخدم من قبل."] } }),
    );

    render(<QuestionForm />);

    fireEvent.change(await screen.findByLabelText("فكرة جديدة"), { target: { value: "مكرّرة" } });
    fireEvent.click(screen.getByRole("button", { name: "أضف الفكرة" }));

    expect(await screen.findByText("الاسم مستخدم من قبل.")).toBeTruthy();
  });
});

/*
| An assistant opens a bank question (`bank.view`) and may not change it
| (`questions.manage`): the form is drawn disabled, and nothing that only writes is
| drawn at all — a «احفظ» that answers 403 is the dead end this mode removes.
*/
describe("QuestionForm, read-only", () => {
  const QUESTION = {
    uuid: "q-1",
    type: "mcq" as const,
    difficulty: "medium" as const,
    bloom_level: "understand" as const,
    is_active: true,
    content: "كم يساوي ٢ + ٢؟",
    points: 1,
    explanation: null,
    options: [
      { uuid: "o-1", content: "٤", is_correct: true },
      { uuid: "o-2", content: "٥", is_correct: false },
      { uuid: "o-3", content: "٦", is_correct: false },
    ],
    created_at: "2026-01-01",
  };

  it("disables every field and draws no save, no new concept and no option controls", async () => {
    render(<QuestionForm question={QUESTION as never} readOnly />);

    await waitFor(() => expect(screen.getByLabelText(/نصّ السؤال/).matches(":disabled")).toBe(true));
    expect(screen.queryByRole("button", { name: "احفظ" })).toBeNull();
    expect(screen.queryByRole("button", { name: "فكرة جديدة" })).toBeNull();
    expect(screen.queryByRole("button", { name: "أضف فكرة" })).toBeNull();
    expect(screen.queryByRole("button", { name: "أضف خياراً" })).toBeNull();
    expect(screen.queryByRole("button", { name: "حذف" })).toBeNull();
    expect(screen.getByRole("link", { name: "عودة إلى البنك" })).toBeTruthy();
  });

  it("stays editable by default", async () => {
    render(<QuestionForm question={QUESTION as never} />);

    await waitFor(() => expect(screen.getByRole("button", { name: "احفظ" })).toBeTruthy());
    expect(screen.getByLabelText(/نصّ السؤال/).matches(":disabled")).toBe(false);
  });
});
