import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| Spec 033 · US1 — deleting an attachment is asked in OUR window.
|
| ⚠️ `fireEvent`, NEVER `userEvent` — the repository rule `ConfirmButton` and
| `PasswordField` both wrote down: the latter awaits real timers between its
| simulated steps and times out rather than failing.
|
| ⚠️ AND THE CANCEL CASE IS THE ONE THAT MATTERS. A window that always confirms
| looks identical to a correct one in every screenshot and in the happy path;
| the only thing that distinguishes them is a delete that did NOT happen.
*/
const remove = vi.fn();

vi.mock("@/lib/media", () => ({
  media: { remove: (...args: unknown[]) => remove(...args) },
}));

vi.mock("@/lib/errors", () => ({
  userMessage: (error: unknown) => (error as { message?: string }).message ?? "خطأ",
}));

vi.mock("./editors/AssetUploader", () => ({
  // Stubbed: this file is about the question, not about uploading. The row keeps
  // its real «حذف» affordance so the test presses what a teacher presses.
  AssetRow: ({ asset, onRemove }: { asset: { original_filename: string }; onRemove: () => void }) => (
    <div>
      <span>{asset.original_filename}</span>
      <button type="button" onClick={onRemove}>
        حذف
      </button>
    </div>
  ),
  AssetUploader: () => null,
}));

import type { MediaAsset } from "@/lib/media";

const { AttachmentsPanel } = await import("./AttachmentsPanel");

/*
  ⚠️ Cast through `unknown` to the real type, not to `never`. `as never` type-
  checks at the call site and then makes every property access on the constant
  an error — `tsc` catches it, `vitest` does not, and the two disagree about a
  file that is green.
*/
const ATTACHMENT = {
  uuid: "aaaa0000-0000-4000-8000-000000000001",
  original_filename: "ورقة عمل.pdf",
} as unknown as MediaAsset;

function renderPanel() {
  return render(
    <AttachmentsPanel
      lessonUuid="cccc0000-0000-4000-8000-000000000001"
      attachments={[ATTACHMENT]}
      onChanged={vi.fn()}
    />,
  );
}

describe("AttachmentsPanel", () => {
  beforeEach(() => remove.mockReset());

  it("asks in our own window, with the shipped sentence unchanged", () => {
    renderPanel();

    fireEvent.click(screen.getByText("حذف"));

    // ⚠️ Compared letter for letter (SC-005). This spec changes the CONTAINER of
    // the question, never its wording — rewording while moving would hide a text
    // change inside a structural diff, where no reviewer can see it.
    expect(screen.getByText("سيُحذف «ورقة عمل.pdf» نهائياً. متابعة؟")).toBeTruthy();
    expect(remove).not.toHaveBeenCalled();
  });

  it("cancelling deletes nothing", () => {
    const { container } = renderPanel();

    fireEvent.click(screen.getByText("حذف"));
    fireEvent.click(screen.getByText("إلغاء"));

    expect(remove).not.toHaveBeenCalled();
    expect(container.querySelector("dialog")).toBeNull();
  });

  it("confirming deletes exactly once", async () => {
    remove.mockResolvedValue(undefined);

    renderPanel();

    fireEvent.click(screen.getByText("حذف"));
    fireEvent.click(screen.getByText("احذف"));

    await waitFor(() => expect(remove).toHaveBeenCalledTimes(1));
    expect(remove).toHaveBeenCalledWith(ATTACHMENT.uuid);
  });

  /*
  | ⛔ THE SERVER'S REFUSAL PATH IS DELIBERATELY NOT A CASE HERE, AND THE REASON
  | IS THE HARNESS RATHER THAN THE CODE.
  |
  | Deleting an asset needs two-factor verification (spec 004), and that refusal
  | must survive the move into the window. It does — verified by probe on
  | 2026-09-09: with `media.remove` rejecting, the rendered document reads
  | «تعذّر حذف المرفق · هذا الإجراء يتطلّب تحقّقاً بخطوتين.», exactly as before.
  |
  | What could not be written is the ASSERTION. `vi.fn()` keeps every return
  | value in `mock.results`, and a rejected promise stored there has no consumer
  | — so vitest reports an unhandled rejection and fails the test with the
  | refusal text as its message, which reads like the assertion failing when in
  | fact it passed. `mockRejectedValue`, a throwing async implementation and a
  | pre-caught promise were all tried and all report it; the only switch is the
  | project-wide `dangerouslyIgnoreUnhandledErrors`, which would hide real
  | unhandled rejections across the whole suite to cover one case.
  |
  | ⚠️ A GREEN TEST HERE WOULD HAVE BEEN WORTH LESS THAN THIS PARAGRAPH. The
  | `catch` block it would guard is untouched by spec 033 — the only change is
  | which control calls `remove()`, and the three cases above measure that.
  */
});
