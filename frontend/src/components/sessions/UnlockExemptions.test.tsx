import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { UnlockExemptions } from "./UnlockExemptions";

const exemptions = vi.fn();
const exempt = vi.fn();

vi.mock("@/lib/unlock-rules", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/unlock-rules")>()),
  unlockRules: {
    exemptions: (uuid: string) => exemptions(uuid),
    exempt: (...args: unknown[]) => exempt(...args),
  },
}));

vi.mock("@/lib/use-teacher-students", () => ({
  useTeacherStudents: () => ({
    canPick: true,
    students: [{ uuid: "st-1", name: "سارة" }],
    failed: false,
  }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  exemptions.mockResolvedValue({ data: [] });
  exempt.mockResolvedValue({ data: { uuid: "ex-1" } });
});

async function mount() {
  await act(async () => {
    render(<UnlockExemptions sessionUuid="s-1" />);
  });
}

describe("UnlockExemptions", () => {
  it("lists who was let past on this session, with the reason", async () => {
    exemptions.mockResolvedValue({
      data: [
        {
          uuid: "ex-1",
          student: { uuid: "st-1", name: "سارة" },
          reason: "ظرفٌ عائلي.",
          granted_at: "2026-09-20T10:00:00Z",
        },
      ],
    });

    await mount();

    expect(exemptions).toHaveBeenCalledWith("s-1");
    expect(screen.getByText("ظرفٌ عائلي.")).toBeTruthy();
  });

  it("will not send an exemption without a reason", async () => {
    await mount();

    fireEvent.change(screen.getByLabelText(/الطالب/), { target: { value: "st-1" } });

    const button = screen.getByRole("button", { name: "استثنِ الطالب" }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);

    fireEvent.change(screen.getByLabelText(/السبب/), { target: { value: "  " } });
    expect(button.disabled).toBe(true);
  });

  it("sends the student and the trimmed reason, then reloads the list", async () => {
    await mount();

    fireEvent.change(screen.getByLabelText(/الطالب/), { target: { value: "st-1" } });
    fireEvent.change(screen.getByLabelText(/السبب/), { target: { value: " ظرفٌ عائلي. " } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "استثنِ الطالب" }));
    });

    expect(exempt).toHaveBeenCalledWith("s-1", "st-1", "ظرفٌ عائلي.");
    expect(exemptions).toHaveBeenCalledTimes(2);
  });
});
