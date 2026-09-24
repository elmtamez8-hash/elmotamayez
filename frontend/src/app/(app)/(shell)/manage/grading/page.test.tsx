import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| The anonymous-grading switch had a route (`PATCH /manage/grading/settings`)
| and no screen. Pinned: the state is read from the queue's META (an empty queue
| has no row to carry it), only `settings.update` sees the switch, and turning
| names back ON — the audited direction — asks first.
*/
const queue = vi.fn();
const setAnonymous = vi.fn();
let mockUser: { permissions: string[] } = { permissions: ["grading.perform", "settings.update"] };

vi.mock("@/lib/grading", () => ({
  grading: {
    queue: () => queue(),
    setAnonymous: (value: boolean) => setAnonymous(value),
  },
}));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: GradingQueuePage } = await import("./page");

function emptyQueue(anonymous: boolean) {
  return { data: [], meta: { total: 0, current_page: 1, last_page: 1, anonymous } };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { permissions: ["grading.perform", "settings.update"] };
});

async function openPage() {
  await act(async () => {
    render(<GradingQueuePage />);
  });
}

describe("the anonymous-grading switch", () => {
  it("reads the setting from an empty queue", async () => {
    queue.mockResolvedValue(emptyQueue(true));
    await openPage();

    expect(screen.getByText(/مفعَّل: تُخفى أسماء الطلاب/)).toBeTruthy();
  });

  it("is not offered to a grader who cannot change settings", async () => {
    mockUser = { permissions: ["grading.perform"] };
    queue.mockResolvedValue(emptyQueue(false));
    await openPage();

    expect(screen.queryByRole("button", { name: "أخفِ الأسماء" })).toBeNull();
    expect(screen.getByText(/يغيّره المدرّس صاحب الحساب/)).toBeTruthy();
  });

  it("hides names on one press", async () => {
    queue.mockResolvedValue(emptyQueue(false));
    setAnonymous.mockResolvedValue({ data: { anonymous: true } });
    await openPage();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أخفِ الأسماء" }));
    });

    expect(setAnonymous).toHaveBeenCalledWith(true);
  });

  it("asks before showing names again, and sends false once confirmed", async () => {
    queue.mockResolvedValue(emptyQueue(true));
    setAnonymous.mockResolvedValue({ data: { anonymous: false } });
    await openPage();

    fireEvent.click(screen.getAllByRole("button", { name: "أظهِر الأسماء" })[0]);
    expect(setAnonymous).not.toHaveBeenCalled();

    // The second button of that name is the dialog's confirmation.
    await act(async () => {
      const buttons = screen.getAllByRole("button", { name: "أظهِر الأسماء" });
      fireEvent.click(buttons[buttons.length - 1]);
    });

    expect(setAnonymous).toHaveBeenCalledTimes(1);
    expect(setAnonymous).toHaveBeenCalledWith(false);
  });
});
