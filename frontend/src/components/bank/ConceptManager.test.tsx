import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

/*
| `PATCH /manage/bank/concepts/{concept}` had no caller. Pinned: the rename
| sends the name alone (the server keeps the subject when the key is absent),
| the default bucket offers no rename, and a duplicate name lands under the field.
*/
const renameConcept = vi.fn();

vi.mock("@/lib/bank", () => ({
  bank: { renameConcept: (uuid: string, name: string) => renameConcept(uuid, name) },
}));

const { ConceptManager } = await import("./ConceptManager");

const concepts = [
  { uuid: "c-default", name: "غير مصنّف", questions_count: 4, is_default: true },
  { uuid: "c-algebra", name: "الجبر", questions_count: 12, is_default: false },
];

beforeEach(() => {
  vi.clearAllMocks();
});

describe("ConceptManager", () => {
  it("offers no rename on the default concept", () => {
    render(<ConceptManager concepts={concepts} onRenamed={() => undefined} />);

    expect(screen.getAllByRole("button", { name: /^أعِد التسمية/ })).toHaveLength(1);
    expect(screen.getByText("الفكرة الافتراضية لا تُعاد تسميتها")).toBeTruthy();
  });

  it("renames with the name alone and hands the new row back", async () => {
    renameConcept.mockResolvedValue({ data: { uuid: "c-algebra", name: "الجبر الخطّي", is_default: false } });
    const onRenamed = vi.fn();
    render(<ConceptManager concepts={concepts} onRenamed={onRenamed} />);

    fireEvent.click(screen.getByRole("button", { name: /^أعِد التسمية/ }));
    fireEvent.change(screen.getByLabelText(/الاسم الجديد/), { target: { value: "  الجبر الخطّي " } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ" }));
    });

    expect(renameConcept).toHaveBeenCalledWith("c-algebra", "الجبر الخطّي");
    // The count the list already had survives — the PATCH answer carries none.
    expect(onRenamed).toHaveBeenCalledWith(
      expect.objectContaining({ uuid: "c-algebra", name: "الجبر الخطّي", questions_count: 12 }),
    );
  });

  it("puts a duplicate-name refusal under the field", async () => {
    renameConcept.mockRejectedValue(
      new ApiError("تحقّق", 422, { errors: { name: ["هذا الاسم مستخدم من قبل."] } }),
    );
    render(<ConceptManager concepts={concepts} onRenamed={() => undefined} />);

    fireEvent.click(screen.getByRole("button", { name: /^أعِد التسمية/ }));
    fireEvent.change(screen.getByLabelText(/الاسم الجديد/), { target: { value: "غير مصنّف" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ" }));
    });

    expect(document.getElementById("concept-c-algebra-error")?.textContent).toBe("هذا الاسم مستخدم من قبل.");
  });
});
