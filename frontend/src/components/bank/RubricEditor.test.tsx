import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import type { BankQuestion } from "@/lib/bank";

/*
| The mark scheme had a route (`PUT …/rubric`) and no screen, which made the
| grading board's per-criterion marks unreachable. Pinned: what is saved is the
| WHOLE list in the shape `SaveRubricRequest` validates, errors land under the
| row they name, and the sum the server refuses is refused here first.
*/
const saveRubric = vi.fn();

vi.mock("@/lib/bank", () => ({
  bank: { saveRubric: (uuid: string, criteria: unknown) => saveRubric(uuid, criteria) },
}));

const { RubricEditor } = await import("./RubricEditor");

function essay(overrides: Partial<BankQuestion> = {}): BankQuestion {
  return {
    uuid: "q-essay",
    type: "essay",
    difficulty: "medium",
    bloom_level: "analyze",
    is_active: true,
    content: "اشرح قانون نيوتن الثاني.",
    points: 10,
    explanation: null,
    created_at: "2026-09-01T00:00:00Z",
    rubric_criteria: [
      { id: 1, label: "المحتوى", max_points: 6, order: 0 },
      { id: 2, label: "اللغة", max_points: 4, order: 1 },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("RubricEditor", () => {
  it("shows the criteria the question already has", () => {
    render(<RubricEditor question={essay()} />);

    expect((screen.getByLabelText(/المعيار 1/) as HTMLInputElement).value).toBe("المحتوى");
    expect((screen.getByLabelText(/المعيار 2/) as HTMLInputElement).value).toBe("اللغة");
  });

  it("saves the complete list with label, max_points and order", async () => {
    saveRubric.mockResolvedValue({ data: [] });
    render(<RubricEditor question={essay()} />);

    fireEvent.click(screen.getByRole("button", { name: "احذف المعيار 1" }));

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ المعايير" }));
    });

    // The first row was removed: what is left is renumbered from zero.
    expect(saveRubric).toHaveBeenCalledWith("q-essay", [{ label: "اللغة", max_points: 4, order: 0 }]);
  });

  it("refuses to send criteria worth more than the question", () => {
    render(<RubricEditor question={essay({ points: 8 })} />);

    expect(
      (screen.getByRole("button", { name: "احفظ المعايير" }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(screen.getByText(/مجموع المعايير أكبر من درجة السؤال/)).toBeTruthy();
  });

  it("puts a per-row validation error under that row", async () => {
    saveRubric.mockRejectedValue(
      new ApiError("تحقّق", 422, { errors: { "criteria.1.label": ["اسم المعيار طويل جداً."] } }),
    );
    render(<RubricEditor question={essay()} />);

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ المعايير" }));
    });

    expect(document.getElementById("criterion-1-label-error")?.textContent).toBe("اسم المعيار طويل جداً.");
  });

  it("prints the server's refusal of a frozen scheme as a sentence", async () => {
    saveRubric.mockRejectedValue(
      new ApiError("صُحِّحت إجاباتٌ على هذه المعايير من قبل، فلا يمكن تغييرها الآن.", 422, {
        message: "صُحِّحت إجاباتٌ على هذه المعايير من قبل، فلا يمكن تغييرها الآن.",
      }),
    );
    render(<RubricEditor question={essay()} />);

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ المعايير" }));
    });

    expect(screen.getByText("صُحِّحت إجاباتٌ على هذه المعايير من قبل، فلا يمكن تغييرها الآن.")).toBeTruthy();
  });
});
