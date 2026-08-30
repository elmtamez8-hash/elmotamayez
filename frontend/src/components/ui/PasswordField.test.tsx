import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { PasswordField } from "./Field";

/*
| THE TOGGLE, AND THE THREE THINGS THAT BREAK IT (spec 022 · FR-014 · SC-005).
|
| ⚠️ `fireEvent`, NOT `userEvent`. The latter awaits real timers between its
| simulated steps, so any test in this repository that also fakes the clock hangs
| rather than failing — the lesson `ConfirmButton` already paid for.
*/

function Harness({ onSubmit }: { onSubmit: () => void }) {
  return (
    <form onSubmit={onSubmit}>
      <PasswordField id="password" label="كلمة المرور" value="hunter2" onChange={vi.fn()} />
    </form>
  );
}

describe("PasswordField", () => {
  it("starts hidden", () => {
    render(<Harness onSubmit={vi.fn()} />);

    const input = screen.getByLabelText("كلمة المرور") as HTMLInputElement;

    expect(input.type).toBe("password");
  });

  it("reveals and hides again on the toggle", () => {
    render(<Harness onSubmit={vi.fn()} />);

    const input = screen.getByLabelText("كلمة المرور") as HTMLInputElement;

    fireEvent.click(screen.getByLabelText("إظهار كلمة المرور"));
    expect(input.type).toBe("text");

    // The label follows the STATE, because the icon says nothing to a screen
    // reader and a fixed label would announce the wrong action half the time.
    fireEvent.click(screen.getByLabelText("إخفاء كلمة المرور"));
    expect(input.type).toBe("password");
  });

  it("does not submit the form it sits in", () => {
    /*
     | ⚠️ THE ONE THAT SHIPS SILENTLY. A bare `<button>` inside a form defaults
     | to `submit`, so the first press of the eye would send a half-filled
     | registration — and on a signup page that is a 422 the person cannot
     | connect to anything they did.
     */
    const onSubmit = vi.fn();

    render(<Harness onSubmit={onSubmit} />);

    fireEvent.click(screen.getByLabelText("إظهار كلمة المرور"));

    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("announces its pressed state", () => {
    render(<Harness onSubmit={vi.fn()} />);

    const toggle = screen.getByLabelText("إظهار كلمة المرور");

    expect(toggle.getAttribute("aria-pressed")).toBe("false");

    fireEvent.click(toggle);

    expect(screen.getByLabelText("إخفاء كلمة المرور").getAttribute("aria-pressed")).toBe("true");
  });

  it("does not carry the revealed state across mounts", () => {
    // Nothing is persisted: a password left visible on an unattended machine is
    // the failure this omission prevents, and there is no `localStorage` call to
    // review because there is none to make.
    const first = render(<Harness onSubmit={vi.fn()} />);

    fireEvent.click(screen.getByLabelText("إظهار كلمة المرور"));
    first.unmount();

    render(<Harness onSubmit={vi.fn()} />);

    expect((screen.getByLabelText("كلمة المرور") as HTMLInputElement).type).toBe("password");
  });
});
