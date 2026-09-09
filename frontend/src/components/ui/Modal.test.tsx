import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { Modal } from "./Modal";

/*
| Spec 033 · the eight contract clauses `C-01`…`C-08`.
|
| ⚠️ `fireEvent`, NEVER `userEvent`. The latter awaits real timers between its
| simulated steps; this repository has paid for that twice already, in
| `ConfirmButton` and in `PasswordField`, where a test under fake timers hung on
| a clock nothing advanced and TIMED OUT rather than failing.
|
| ⚠️ AND WHAT THIS FILE DOES NOT MEASURE IS WRITTEN DOWN IN `vitest.setup.ts`:
| jsdom implements neither `showModal()` nor `close()`, so both are shimmed, and
| the focus trap and the inert background are the browser's — not ours and not
| asserted here. Claiming otherwise would be the vacuous-assertion shape this
| tree keeps recording. What IS measured is everything the component decides.
*/
function open(props: Partial<React.ComponentProps<typeof Modal>> = {}) {
  const onConfirm = vi.fn();
  const onCancel = vi.fn();

  const view = render(
    <Modal
      open
      title="حذف الفصل"
      message="سيُحذف «الفصل الأول» وكل ما بداخله. متابعة؟"
      confirmLabel="احذف"
      onConfirm={onConfirm}
      onCancel={onCancel}
      tone="danger"
      {...props}
    />,
  );

  const dialog = view.container.querySelector("dialog") as HTMLDialogElement;

  return { ...view, dialog, onConfirm, onCancel };
}

describe("Modal", () => {
  it("C-01 · C-02 — opens as a modal and shows the question", () => {
    const { dialog } = open();

    expect(dialog.open).toBe(true);
    expect(screen.getByText("حذف الفصل")).toBeTruthy();
    expect(screen.getByText("سيُحذف «الفصل الأول» وكل ما بداخله. متابعة؟")).toBeTruthy();
  });

  it("C-03 — the cancel button cancels, and never confirms", () => {
    const { onConfirm, onCancel } = open();

    fireEvent.click(screen.getByText("إلغاء"));

    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("C-03 — Escape cancels through the same one listener", () => {
    const { dialog, onConfirm, onCancel } = open();

    /*
      The browser fires `cancel` then `close` for Escape and `close` alone for
      our own button — which is exactly why the component listens to `close` and
      nothing else. Firing `close` here is firing what a real Escape produces.
    */
    fireEvent(dialog, new Event("close"));

    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("C-03 — a click on the backdrop cancels", () => {
    const { dialog, onConfirm, onCancel } = open();

    // A backdrop click is reported against the <dialog> element itself; there is
    // no separate node for `::backdrop` to be the target of.
    fireEvent.click(dialog);

    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("C-04 — the confirm button confirms, once", () => {
    const { onConfirm, onCancel } = open();

    fireEvent.click(screen.getByText("احذف"));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onCancel).not.toHaveBeenCalled();
  });

  it("C-06 — a click on a child does NOT close the window", () => {
    /*
    | ⛔ THE CLAUSE NOBODY WRITES AND EVERYBODY PAYS FOR. Without the
    | `event.target === dialogEl` comparison, the click that reaches the confirm
    | button ALSO bubbles to the dialog and closes it — so the window shuts, the
    | action never runs, and the feature reads as «the button does nothing».
    |
    | It is asserted on the CONFIRM button rather than on an inert child on
    | purpose: an inert child would pass against a component that closes on every
    | bubbled click but happens to run `onConfirm` first.
    */
    const { dialog, onConfirm, onCancel } = open();

    fireEvent.click(screen.getByText("احذف"));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onCancel).not.toHaveBeenCalled();
    expect(dialog.open).toBe(true);
  });

  it("C-07 — busy disables confirming but never traps the reader", () => {
    const { onConfirm, onCancel } = open({ busy: true });

    // ⚠️ Queried by role, not by label: `Button` swaps the label for its own
    // «جارٍ التنفيذ…» while loading, so `getByText("احذف")` throws — an error
    // that reads as a broken test rather than as the disabled state it is.
    const confirm = screen.getByRole("button", { name: /جارٍ التنفيذ/ });

    expect(confirm.hasAttribute("disabled")).toBe(true);

    fireEvent.click(confirm);
    expect(onConfirm).not.toHaveBeenCalled();

    // ⚠️ The escape hatch stays open. A window whose only two controls are both
    // dead while a request hangs is a page the reader has to reload.
    fireEvent.click(screen.getByText("إلغاء"));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("C-08 — renders nothing at all when closed", () => {
    const { container } = render(
      <Modal
        open={false}
        title="حذف الفصل"
        confirmLabel="احذف"
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    expect(container.querySelector("dialog")).toBeNull();
    expect(screen.queryByText("حذف الفصل")).toBeNull();
  });

  it("carries children — the same component asks for a title, not a second one", () => {
    // US3's field is CONTENT, not a third kind of window. Two near-identical
    // components are two answers to one question that drift at the first edit.
    open({ children: <input aria-label="عنوان العنصر الجديد" /> });

    expect(screen.getByLabelText("عنوان العنصر الجديد")).toBeTruthy();
  });
});
