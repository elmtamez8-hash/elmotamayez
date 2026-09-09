import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CohortPicker } from "./CohortPicker";
import type { CohortOption } from "@/lib/cohorts";

/*
| شاشةُ اختيارِ المجموعة (FR-028أ).
|
| ⚠️ NONE OF THIS IS REACHABLE FROM A BACKEND TEST. The server answers a correct
| list and a correct refusal and knows nothing about what is drawn from them: a
| card that offers «انضمّ» on a full group, or a `seats_left: null` printed as
| «٠ مقاعد», is a perfectly good `200`.
*/

const join = vi.fn();

vi.mock("@/lib/cohorts", () => ({
  cohorts: { join: (uuid: string) => join(uuid) },
}));

function option(overrides: Partial<CohortOption> = {}): CohortOption {
  return {
    uuid: "c-1",
    name: "السبت ٤م",
    description: null,
    status: "open",
    capacity: null,
    seats_left: 3,
    is_full: false,
    is_joinable: true,
    members_count: 12,
    schedule_preview: ["السبت ١٦:٠٠", "الثلاثاء ١٦:٠٠"],
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  join.mockResolvedValue({});
});

describe("CohortPicker", () => {
  it("shows the times and the remaining places, because a name alone is not a choice", () => {
    render(<CohortPicker options={[option()]} message="اختر مجموعتك للبدء." onJoined={vi.fn()} />);

    expect(screen.getByText(/السبت ١٦:٠٠/)).toBeTruthy();
    expect(screen.getByText(/٣|3/)).toBeTruthy();
    expect(screen.getByText("اختر مجموعتك للبدء.")).toBeTruthy();
  });

  /*
   | ⚠️ NULL IS «NO CEILING», NOT ZERO. Printed as a number it would render the
   | most open group in the course as the one nobody can join.
  */
  it("says «بلا حدّ» rather than a number when no capacity was declared", () => {
    render(
      <CohortPicker options={[option({ seats_left: null })]} message={null} onJoined={vi.fn()} />,
    );

    expect(screen.getByText("بلا حدّ للمقاعد")).toBeTruthy();
    expect(screen.queryByText(/المقاعد المتبقّية/)).toBeNull();
  });

  it("will not offer a full group, and says which one it is", () => {
    render(
      <CohortPicker
        options={[option({ is_full: true, is_joinable: false, seats_left: 0 })]}
        message={null}
        onJoined={vi.fn()}
      />,
    );

    expect(screen.getByText("مكتملة")).toBeTruthy();
    expect(screen.getByRole("button", { name: /انضمّ/ }).hasAttribute("disabled")).toBe(true);
  });

  it("marks a group closed to new joins without calling it full", () => {
    render(
      <CohortPicker
        options={[option({ status: "closed", is_joinable: false })]}
        message={null}
        onJoined={vi.fn()}
      />,
    );

    expect(screen.getByText("مغلقة")).toBeTruthy();
    expect(screen.queryByText("مكتملة")).toBeNull();
  });

  it("joins the group that was pressed", async () => {
    const onJoined = vi.fn();

    render(
      <CohortPicker
        options={[option(), option({ uuid: "c-2", name: "الأحد ٦م" })]}
        message={null}
        onJoined={onJoined}
      />,
    );

    fireEvent.click(screen.getAllByRole("button", { name: /انضمّ/ })[1]);

    await waitFor(() => expect(join).toHaveBeenCalledWith("c-2"));
    await waitFor(() => expect(onJoined).toHaveBeenCalled());
  });

  /*
   | ⚠️ A GROUP CAN FILL BETWEEN THE PAINT AND THE TAP, AND THE REST OF THE LIST
   | MUST SURVIVE IT. Reloading the whole page instead throws away the reader's
   | place on a screen whose entire job is comparing options side by side.
  */
  it("greys the group that filled under it and leaves the others pressable", async () => {
    join.mockRejectedValueOnce({ body: { code: "cohort_full", message: "اكتملت مقاعد هذه المجموعة." } });

    render(
      <CohortPicker
        options={[option(), option({ uuid: "c-2", name: "الأحد ٦م" })]}
        message={null}
        onJoined={vi.fn()}
      />,
    );

    fireEvent.click(screen.getAllByRole("button", { name: /انضمّ/ })[0]);

    await waitFor(() => expect(screen.getByText("مكتملة")).toBeTruthy());

    const buttons = screen.getAllByRole("button", { name: /انضمّ/ });

    expect(buttons[0].hasAttribute("disabled")).toBe(true);
    expect(buttons[1].hasAttribute("disabled")).toBe(false);
  });

  /*
   | ⚠️ AND THE VALVE'S STATE READS AS AN ANSWER. When nothing is joinable the
   | curriculum below this card is fully open — a bare «لا توجد مجموعات» over an
   | open course reads as a fault instead.
  */
  it("says the course is open to them when there is nothing to join", () => {
    render(
      <CohortPicker
        options={[option({ is_full: true, is_joinable: false })]}
        message={null}
        onJoined={vi.fn()}
      />,
    );

    expect(screen.getByText(/يمكنك متابعة المنهج/)).toBeTruthy();
  });
});
