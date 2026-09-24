import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import { TeacherSignupWizard } from "./TeacherSignupWizard";

/*
| Step 4 of the teacher application.
|
| ⚠️ `send()` swallows its own error, and `submitStepFour` carried on to
| `/teacher/application/submit` regardless — so a refused price or timetable
| still submitted the application with the step-4 values from BEFORE this
| attempt, and the refusal the teacher needed sat on a screen they had been
| navigated away from. And a cleared time field travelled as `""`, which the
| UTC conversion reads as midnight.
*/

const get = vi.fn();
const put = vi.fn();
const post = vi.fn();
const push = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api")>();

  return {
    ...actual,
    api: {
      get: (...args: unknown[]) => get(...args),
      put: (...args: unknown[]) => put(...args),
      post: (...args: unknown[]) => post(...args),
    },
  };
});

vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ adoptSession: vi.fn() }) }));

const APPLICATION = {
  status: "draft",
  current_step: 4,
  rejection_reason: null,
  step_data: {
    step_4: {
      hourly_rate: "80",
      availability: [{ day_of_week: 1, start_time: "09:00:00", end_time: "11:00:00" }],
    },
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.setItem("auth_token", "t");
  get.mockResolvedValue({ application: APPLICATION });
  post.mockResolvedValue({});
});

afterEach(() => {
  localStorage.removeItem("auth_token");
});

async function openStepFour() {
  await act(async () => {
    render(<TeacherSignupWizard subjects={[]} gradeLevels={[]} />);
  });

  return screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
}

describe("the last step of the teacher application", () => {
  it("does not submit the application when saving step 4 is refused", async () => {
    put.mockRejectedValue(
      new ApiError("invalid", 422, { errors: { hourly_rate: ["السعر أكبر من الحد المسموح."] } }),
    );

    const submit = await openStepFour();

    await act(async () => {
      fireEvent.click(submit);
    });

    expect(put).toHaveBeenCalledWith("/teacher/application/step-4", expect.anything());
    expect(post).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
    expect(screen.getByText("السعر أكبر من الحد المسموح.")).toBeDefined();
  });

  it("submits once step 4 is saved", async () => {
    put.mockResolvedValue({ application: APPLICATION });

    const submit = await openStepFour();

    await act(async () => {
      fireEvent.click(submit);
    });

    expect(post).toHaveBeenCalledWith("/teacher/application/submit");
    expect(push).toHaveBeenCalledWith("/signup/teacher/submitted");
  });

  it("refuses a cleared time rather than sending midnight", async () => {
    const submit = await openStepFour();

    const [start] = Array.from(document.querySelectorAll('input[type="time"]'));
    fireEvent.change(start, { target: { value: "" } });

    await act(async () => {
      fireEvent.click(submit);
    });

    expect(put).not.toHaveBeenCalled();
    expect(screen.getByText("أكمل وقتَي البداية والنهاية في كلّ فترة قبل الحفظ.")).toBeDefined();
  });
});
