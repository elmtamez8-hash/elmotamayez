import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import FamilyPage from "./page";

/*
| «المرتبطون» — الشاشةُ التي يقرؤُها **جمهوران**، وقد كُتِبَت لواحدٍ منهما.
|
| ⚠️ `‎/family` بلا قيدِ جمهورٍ في القائمةِ الجانبيّة، فالطالبُ يصلُها منذُ أن
| شُحِنَت — ويقرأُ صفّاً عنوانُه **اسمُه هو** وزرُّه الوحيدُ «إلغاء الارتباط».
| الرابطُ المعلَّقُ الذي ينتظرُ قرارَه كان غيرَ مرئيٍّ تماماً، ولا مسارَ في المنتَجِ
| كلِّه يستطيعُ تنشيطَه.
|
| ولم يكن للملفِّ اختبارٌ من أيِّ نوع. هذا أوّلُه، ويقيسُ السؤالَين اللذَين يقرِّرانِ
| الشاشة: **باسمِ من** يُرسَمُ الصفّ، و**متى** يظهرُ زرُّ القبول.
*/

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
const del = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: (path: string, body: unknown) => post(path, body),
    patch: (path: string, body: unknown) => patch(path, body),
    delete: (path: string) => del(path),
  },
}));

function relation(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "r-1",
    relation_type: "parent",
    relation_type_label: "وليّ أمر",
    status: "pending",
    status_label: "بانتظار القبول",
    student_name: "كريم",
    student_age: 14,
    student_grade_level_slug: null,
    student_school_year_slug: null,
    student_school_year_name: null,
    student_has_account: true,
    viewer_side: "student",
    can_decide: true,
    accepted_at: null,
    guardian: { uuid: "g-1", name: "أم كريم" },
    permissions: [{ key: "payments", label: "المدفوعات والمستحقّات" }],
    revoked_at: null,
    created_at: null,
    ...overrides,
  };
}

describe("family page", () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    del.mockReset();
  });

  it("shows a student the GUARDIAN's name, not their own", async () => {
    get.mockResolvedValue({ data: [relation()] });

    render(<FamilyPage />);

    /*
     * The whole defect in one assertion. The row is about كريم, and the screen
     * used to head it «كريم» — the reader's own name — because it rendered
     * `student_name` whatever side was looking.
     */
    expect(await screen.findByText("أم كريم")).toBeTruthy();
    expect(screen.queryByText("كريم")).toBeNull();
    expect(screen.getByRole("heading", { name: "من يتابعني" })).toBeTruthy();
  });

  it("offers accept only when the server says the reader may decide", async () => {
    get.mockResolvedValue({ data: [relation()] });
    post.mockResolvedValue(relation({ status: "active", can_decide: false }));

    render(<FamilyPage />);

    const accept = await screen.findByRole("button", { name: "قبول" });

    fireEvent.click(accept);

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith("/family/relations/r-1/accept", {}),
    );
  });

  /*
   * ⚠️ THE BUTTON READS THE SERVER'S FLAG AND NOTHING ELSE. Re-deriving «pending
   * and I am not the requester» here is the two-spellings defect — and the
   * server's copy also knows about a link created before this feature existed,
   * which nobody may settle because nobody can prove who asked for it. A screen
   * that derived the condition itself would draw a button the door answers 403.
   */
  it("draws no accept button on a pending row the server refuses", async () => {
    get.mockResolvedValue({ data: [relation({ can_decide: false })] });

    render(<FamilyPage />);

    expect(await screen.findByText("أم كريم")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "قبول" })).toBeNull();
  });

  it("keeps the guardian's own list under its own heading", async () => {
    get.mockResolvedValue({
      data: [relation({ viewer_side: "guardian", status: "active", can_decide: false })],
    });

    render(<FamilyPage />);

    expect(await screen.findByText("كريم")).toBeTruthy();
    expect(screen.queryByRole("heading", { name: "من يتابعني" })).toBeNull();
  });

  /*
   * `PATCH /family/relations/{uuid}` shipped with a policy, a rule and ZERO
   * callers anywhere in `frontend/src` — so the narrow-only rule guarded a door
   * nobody could open. This is that door.
   */
  it("saves a narrowed permission set through the editor", async () => {
    get.mockResolvedValue({
      data: [relation({ viewer_side: "guardian", status: "active", can_decide: false })],
    });
    patch.mockResolvedValue(relation({ viewer_side: "guardian", status: "active" }));

    render(<FamilyPage />);

    fireEvent.click(await screen.findByRole("button", { name: "تعديل الصلاحيات" }));

    /*
     * ⚠️ SCOPED TO THE EDITOR'S OWN FIELDSET. The «إضافة مرتبط» card at the bottom
     * of the page renders the SAME six permission checkboxes with the same Arabic
     * labels, so an unscoped query matches two — and a test that picked the wrong
     * one would tick a box on the add form and assert nothing about this feature.
     */
    const editor = within(screen.getByRole("group", { name: "ما تطّلع عليه" }));

    fireEvent.click(editor.getByRole("checkbox", { name: "المدفوعات والمستحقّات" }));
    fireEvent.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() =>
      expect(patch).toHaveBeenCalledWith("/family/relations/r-1", { permissions: [] }),
    );
  });
});
