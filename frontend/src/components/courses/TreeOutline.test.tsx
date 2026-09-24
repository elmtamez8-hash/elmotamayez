import { act, fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { TreeOutline } from "./TreeOutline";
import type { CourseTree } from "@/lib/courses";

/*
| An inline title survives a refused save.
|
| The new-section and new-chapter boxes were emptied, and the rename box closed,
| the moment the request LEFT — so a 422 took the teacher's typing with it.
| They clear on success only now.
*/

const TREE: CourseTree = {
  uuid: "c-1",
  title: "الفيزياء",
  status: "published",
  is_sequential: false,
  structure_version: 1,
  sections: [
    {
      uuid: "sec-1",
      title: "الميكانيكا",
      order: 1,
      status: "draft",
      status_label: "مسودّة",
      chapters: [],
    },
  ],
};

function renderTree(overrides: Partial<Parameters<typeof TreeOutline>[0]> = {}) {
  const props = {
    tree: TREE,
    busy: false,
    onAddSection: vi.fn(() => Promise.resolve(false)),
    onAddChapter: vi.fn(() => Promise.resolve(false)),
    onAddLesson: vi.fn(),
    onRename: vi.fn(() => Promise.resolve(false)),
    onDelete: vi.fn(),
    onMoveSection: vi.fn(),
    onMoveChapter: vi.fn(),
    onMoveLesson: vi.fn(),
    onEditLesson: vi.fn(),
    onSetStatus: vi.fn(),
    ...overrides,
  };

  render(<TreeOutline {...props} />);

  return props;
}

describe("inline writes in the course tree", () => {
  it("keeps a new section's title when the server refuses it", async () => {
    const props = renderTree();
    const box = screen.getByLabelText("عنوان القسم الجديد") as HTMLInputElement;

    fireEvent.change(box, { target: { value: "الكهرباء" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "إضافة قسم" }));
    });

    expect(props.onAddSection).toHaveBeenCalledWith("الكهرباء");
    expect(box.value).toBe("الكهرباء");
  });

  it("clears a new chapter's title once it was added", async () => {
    renderTree({ onAddChapter: vi.fn(() => Promise.resolve(true)) });
    const box = screen.getByLabelText("عنوان الفصل الجديد") as HTMLInputElement;

    fireEvent.change(box, { target: { value: "الحركة" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "إضافة فصل" }));
    });

    expect(box.value).toBe("");
  });

  it("leaves the rename box open, holding the new name, over a refusal", async () => {
    const props = renderTree();

    fireEvent.click(screen.getByRole("button", { name: "تسمية" }));
    const box = screen.getByLabelText("الاسم الجديد لـ «الميكانيكا»") as HTMLInputElement;
    fireEvent.change(box, { target: { value: "الميكانيكا الكلاسيكية" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "حفظ" }));
    });

    expect(props.onRename).toHaveBeenCalledWith("section", "sec-1", "الميكانيكا الكلاسيكية");
    expect((screen.getByLabelText("الاسم الجديد لـ «الميكانيكا»") as HTMLInputElement).value).toBe(
      "الميكانيكا الكلاسيكية",
    );
  });
});
