import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import { MultiSelectField } from "./Field";

/*
| Spec: the three teacher fields moved off walls of chips onto one control.
|
| ⚠️ `fireEvent`, NEVER `userEvent` — the repository rule `ConfirmButton` and
| `PasswordField` both wrote down.
|
| ⚠️ AND THE CLOSED STATE IS THE HALF THAT MATTERS. A panel that never closes
| looks identical to a correct one in every screenshot and in the happy path;
| what distinguishes them is an option that is NOT in the document.
*/
const OPTIONS = [
  { value: "physics", label: "الفيزياء" },
  { value: "maths", label: "الرياضيات" },
  { value: "arabic", label: "العربية" },
];

function Harness({ initial = [] as string[], onChange = vi.fn() }) {
  const [value, setValue] = useState(initial);

  return (
    <MultiSelectField
      id="subjects"
      label="المواد التي تدرّسها"
      required
      placeholder="اختر المواد"
      options={OPTIONS}
      value={value}
      onChange={(next) => {
        setValue(next);
        onChange(next);
      }}
    />
  );
}

describe("MultiSelectField", () => {
  it("keeps the options behind the trigger until it is pressed", () => {
    render(<Harness />);

    expect(screen.queryByLabelText("الفيزياء")).toBeNull();

    fireEvent.click(openTrigger());

    expect(screen.getByLabelText("الفيزياء")).toBeTruthy();
  });

  /*
  | ⚠️ THE TRIGGER IS FOUND BY ITS *LABEL*, NOT BY ITS TEXT. A `<button>` is
  | labelable, so `Field`'s existing `<label htmlFor>` names it — which is the
  | whole reason the trigger is a button rather than a div, and it means the
  | accessible name is «المواد التي تدرّسها» whatever the summary reads. The
  | summary is checked as TEXT, separately.
  */
  const openTrigger = () => screen.getByRole("button", { name: /المواد التي تدرّسها/ });

  it("shows the chosen labels as one Arabic sentence", () => {
    render(<Harness initial={["physics", "maths"]} />);

    // `Intl.ListFormat`, so «و» joins the last pair — not a bare «، ».
    expect(screen.getByText("الفيزياء والرياضيات")).toBeTruthy();
  });

  it("shows the placeholder when nothing is chosen", () => {
    render(<Harness />);

    expect(screen.getByText("اختر المواد")).toBeTruthy();
  });

  it("ADDS to the selection rather than replacing it", () => {
    /*
    | ⛔ THIS IS WHY THE NATIVE `<select multiple>` WAS REJECTED. A plain click
    | there replaces the whole selection, so a teacher holding two subjects who
    | picks a third is left with one — on a required field, silently. Same
    | family as the second tap that turned a right answer into a zero.
    */
    const onChange = vi.fn();
    render(<Harness initial={["physics", "maths"]} onChange={onChange} />);

    fireEvent.click(openTrigger());
    fireEvent.click(screen.getByLabelText("العربية"));

    expect(onChange).toHaveBeenCalledWith(["physics", "maths", "arabic"]);
  });

  it("unticking removes exactly that one", () => {
    const onChange = vi.fn();
    render(<Harness initial={["physics", "maths"]} onChange={onChange} />);

    fireEvent.click(openTrigger());
    fireEvent.click(screen.getByLabelText("الفيزياء"));

    expect(onChange).toHaveBeenCalledWith(["maths"]);
  });

  it("closes on Escape and hands focus back to the trigger", () => {
    render(<Harness />);

    const trigger = openTrigger();
    fireEvent.click(trigger);
    fireEvent.keyDown(screen.getByLabelText("الفيزياء"), { key: "Escape" });

    expect(screen.queryByLabelText("الفيزياء")).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  it("closes on a press outside it", () => {
    render(
      <div>
        <Harness />
        <button type="button">في مكان آخر</button>
      </div>,
    );

    fireEvent.click(openTrigger());
    fireEvent.mouseDown(screen.getByText("في مكان آخر"));

    expect(screen.queryByLabelText("الفيزياء")).toBeNull();
  });

  it("announces its state to a screen reader", () => {
    render(<Harness />);

    const trigger = openTrigger();

    expect(trigger.getAttribute("aria-expanded")).toBe("false");
    expect(trigger.getAttribute("aria-required")).toBe("true");

    fireEvent.click(trigger);

    expect(trigger.getAttribute("aria-expanded")).toBe("true");
  });
});
