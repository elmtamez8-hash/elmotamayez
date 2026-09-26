import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageFreezePage from "./page";

/*
| ⛔ «جُمّدت الفترة» بقيَت بعدَ رفعِ التجميد (2026-09-26): رفعُ الفترةِ أعادَ تحميلَ
| القائمةِ وتركَ لافتةَ الإنشاءِ — وقائمةَ الحصصِ المعلَّقة — فوقَها، تُبلِّغُ عن
| تجميدٍ لم يعدْ موجوداً.
*/
const list = vi.fn();
const create = vi.fn();
const remove = vi.fn();

vi.mock("@/lib/class-sessions", () => ({
  freezePeriods: {
    list: () => list(),
    create: (body: unknown) => create(body),
    remove: (uuid: string) => remove(uuid),
  },
}));

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  fieldErrors: () => ({}),
}));

const PERIOD = { uuid: "f-1", starts_on: "2026-10-01", ends_on: "2026-10-07", reason: "إجازة", student: null };

beforeEach(() => {
  vi.clearAllMocks();
  list.mockResolvedValue({ data: [PERIOD] });
  create.mockResolvedValue({ notified: 3, suspended: [{ uuid: "s-1", title: "حصة", starts_at: "2026-10-02" }] });
  remove.mockResolvedValue({ deleted: true });
});

describe("lifting a freeze", () => {
  it("clears the «جُمّدت الفترة» banner it no longer describes", async () => {
    render(<ManageFreezePage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    fireEvent.change(document.getElementById("starts_on") as HTMLInputElement, {
      target: { value: "2026-10-01" },
    });
    fireEvent.change(document.getElementById("ends_on") as HTMLInputElement, {
      target: { value: "2026-10-07" },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تجميد الفترة" }));
    });

    expect(await screen.findByText("جُمّدت الفترة")).toBeTruthy();
    expect(screen.getByText("حصص عُلِّقت")).toBeTruthy();

    // Two presses: `ConfirmButton` arms, then acts.
    fireEvent.click(await screen.findByRole("button", { name: "رفع التجميد" }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اضغط مجدداً لرفع التجميد" }));
    });

    await waitFor(() => expect(remove).toHaveBeenCalledWith("f-1"));
    expect(screen.queryByText("جُمّدت الفترة")).toBeNull();
    expect(screen.queryByText("حصص عُلِّقت")).toBeNull();
    expect(screen.getByText("رُفع التجميد")).toBeTruthy();
  });

  it("names a failed lift as a failed LIFT, not as a failed freeze", async () => {
    remove.mockRejectedValue(new Error("offline"));

    render(<ManageFreezePage />);

    fireEvent.click(await screen.findByRole("button", { name: "رفع التجميد" }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اضغط مجدداً لرفع التجميد" }));
    });

    expect(await screen.findByText("تعذّر رفع التجميد")).toBeTruthy();
    expect(screen.queryByText("تعذّر التجميد")).toBeNull();
  });
});
