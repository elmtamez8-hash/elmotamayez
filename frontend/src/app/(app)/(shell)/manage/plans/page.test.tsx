import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@/lib/api";

/*
| ٠٣٦ — THE TEACHER'S OWN PLAN SCREEN, AND TWO DEFECTS A REVIEW FOUND IN IT.
|
| ⛔ (1) EDITING A SESSION-SHAPED PLAN SILENTLY MADE IT A MONTH. The screen had
| no session-count field at all, so `String(row.duration_days)` put the literal
| text «null» into a required NUMBER input; the browser sanitised it, the field
| rendered EMPTY, and the teacher filled in a duration. The payload then carried
| a duration and no count — and `session_count` IS fillable — so a twelve-lesson
| plan became a thirty-day subscription at the price the platform had set for
| twelve lessons. Nothing failed anywhere.
|
| ⛔ (2) EDITING A GROUP-COVERED PLAN WAS A DEAD BUTTON. The coverage select had
| no «مجموعة» option, so the save sent a null coverage, the server answered 422
| under `coverage_uuid` — and the only renderer of that message sat inside a
| `coverage_type === "course"` branch. The teacher pressed «احفظ», the button came
| back, and nothing happened and nothing was said.
|
| ⚠️ WHAT IS MEASURED IS THE PAYLOAD, NOT THE MARKUP. The rule being kept lives on
| the server; what this screen owes it is to send what the teacher chose. An
| assertion on which fields are visible would pass over a form that renders
| perfectly and posts the wrong body.
*/
const list = vi.fn();
const create = vi.fn();
const update = vi.fn();
const requestChange = vi.fn();
const changeRequests = vi.fn();
const cohortList = vi.fn();

vi.mock("@/lib/plans", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/plans")>();

  return {
    ...actual,
    plans: {
      ...actual.plans,
      manage: {
        list: () => list(),
        create: (body: unknown) => create(body),
        update: (uuid: string, body: unknown) => update(uuid, body),
        requestChange: (uuid: string, body: unknown) => requestChange(uuid, body),
        changeRequests: () => changeRequests(),
      },
    },
  };
});

vi.mock("@/lib/cohorts", () => ({
  manageCohorts: { list: (uuid: string) => cohortList(uuid) },
}));

vi.mock("@/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api")>();

  return {
    ...actual,
    api: { ...actual.api, get: async () => ({ data: [{ uuid: "course-1", title: "التفاضل" }] }) },
  };
});

/** A plan sold by sessions, priced — the row that used to be destroyed by an edit. */
const SESSIONS_PLAN = {
  uuid: "plan-sessions",
  title: "اثنتا عشرة حصّة",
  duration_days: null,
  session_count: 12,
  session_type: "group" as const,
  coverage_type: "course" as const,
  coverage_label: "كورس واحد",
  coverage_uuid: "course-1",
  price_minor: 60_000,
  currency: "QAR",
  is_active: true,
  is_sellable: true,
};

/** One sold by the month, and NOT yet priced — the teacher may still edit it. */
const UNPRICED_PLAN = {
  ...SESSIONS_PLAN,
  uuid: "plan-month",
  title: "الشهري",
  duration_days: 30,
  session_count: null,
  price_minor: null,
  is_sellable: false,
};

async function open(rows: unknown[]) {
  list.mockResolvedValue({ data: rows });
  changeRequests.mockResolvedValue({ data: [] });
  cohortList.mockResolvedValue({ data: [{ uuid: "cohort-1", name: "مجموعة الجمعة" }] });

  const { default: Page } = await import("./page");

  render(<Page />);

  await screen.findByText(rows.length === 0 ? "لا باقات بعد" : (rows[0] as { title: string }).title);
}

beforeEach(() => {
  vi.resetModules();
  list.mockReset();
  create.mockReset();
  update.mockReset();
  requestChange.mockReset();
  changeRequests.mockReset();
  cohortList.mockReset();
});

describe("editing a plan the platform has priced", () => {
  it("asks for a change instead of saving, and keeps the count", async () => {
    await open([SESSIONS_PLAN]);

    requestChange.mockResolvedValue({ data: {} });

    fireEvent.click(screen.getByRole("button", { name: "اطلب تعديلاً" }));

    // ⛔ THE COUNT IS IN THE FIELD, not the word «null» in an empty duration box.
    expect(screen.getByLabelText(/عدد الحصص/)).toHaveProperty("value", "12");
    expect(screen.queryByLabelText(/المدّة بالأيّام/)).toBeNull();

    fireEvent.change(screen.getByLabelText(/عدد الحصص/), { target: { value: "20" } });
    fireEvent.click(screen.getByRole("button", { name: "أرسِل الطلب" }));

    await waitFor(() => expect(requestChange).toHaveBeenCalledTimes(1));

    expect(update).not.toHaveBeenCalled();
    expect(requestChange.mock.calls[0][0]).toBe("plan-sessions");
    expect(requestChange.mock.calls[0][1]).toMatchObject({
      session_count: 20,
      // ⛔ AND THE OTHER SHAPE IS SENT AS null, NEVER LEFT OUT. The form keeps the
      // state of a field it stopped showing, and a body carrying both is refused.
      duration_days: null,
      coverage_type: "course",
      coverage_uuid: "course-1",
    });
  });
});

describe("a plan nobody has priced yet", () => {
  it("is saved directly, because nothing has been priced to move", async () => {
    await open([UNPRICED_PLAN]);

    update.mockResolvedValue({ data: {} });

    fireEvent.click(screen.getByRole("button", { name: "تعديل" }));
    fireEvent.change(screen.getByLabelText(/المدّة بالأيّام/), { target: { value: "60" } });
    fireEvent.click(screen.getByRole("button", { name: "احفظ التعديل" }));

    await waitFor(() => expect(update).toHaveBeenCalledTimes(1));

    expect(requestChange).not.toHaveBeenCalled();
    expect(update.mock.calls[0][1]).toMatchObject({ duration_days: 60, session_count: null });
  });
});

describe("writing a plan for one group", () => {
  it("sends the group's identifier, not the course's", async () => {
    /*
    | ⛔ THE COVERAGE HAD NO «مجموعة» OPTION AT ALL, so the teacher's half of the
    | row — which the server's own Action calls the teacher's half — could only be
    | written from the platform officer's panel.
    */
    await open([]);

    create.mockResolvedValue({ data: {} });

    fireEvent.change(screen.getByLabelText(/اسم الباقة/), { target: { value: "الجمعة" } });
    fireEvent.change(screen.getByLabelText(/ما الذي تبيعه/), { target: { value: "sessions" } });
    fireEvent.change(screen.getByLabelText(/عدد الحصص/), { target: { value: "8" } });
    fireEvent.change(screen.getByLabelText(/ما الذي تغطّيه/), { target: { value: "cohort" } });
    fireEvent.change(screen.getByLabelText(/الكورس/), { target: { value: "course-1" } });

    // المجموعاتُ تُقرَأُ من الكورسِ المختارِ وحدَه.
    await waitFor(() => expect(cohortList).toHaveBeenCalledWith("course-1"));

    fireEvent.change(await screen.findByLabelText(/^المجموعة/), { target: { value: "cohort-1" } });
    fireEvent.click(screen.getByRole("button", { name: "أضِف الباقة" }));

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1));

    expect(create.mock.calls[0][0]).toMatchObject({
      coverage_type: "cohort",
      // ⚠️ THE GROUP, NOT THE COURSE IT IS IN. The course is how the group is
      // found; the column holds the group.
      coverage_uuid: "cohort-1",
      session_count: 8,
      duration_days: null,
    });
  });
});

describe("stopping a plan that a group depends on (036 · FR-013)", () => {
  /*
  | ⛔ THE WARNING IS «BEFORE», AND BOTH HALVES ARE MEASURED HERE. The server
  | runs the save, reads the price gate on both sides of it, rolls the whole
  | thing back and refuses with the names — so what this screen owes it is to
  | show them and to re-send THE SAME body with the acknowledgement. A screen
  | that rebuilt the payload could execute something the warning never described.
  |
  | ⚠️ AND IT BRANCHES ON THE `code`, NOT ON THE SENTENCE. Every other refusal
  | here is final, so a match on Arabic prose would offer «نفِّذ» under a message
  | that acknowledging cannot get past.
  */
  const refusal = () =>
    new ApiError("هذا التعديل يُخرِج مجموعة واحدة فيها طلاب من العرض: مجموعة السبت.", 422, {
      message: "…",
      code: "plan_would_hide_cohorts",
      cohorts: ["مجموعة السبت"],
    });

  it("asks first, writes only after the teacher says they know", async () => {
    await open([UNPRICED_PLAN]);

    update.mockRejectedValueOnce(refusal()).mockResolvedValueOnce({ data: {} });

    fireEvent.click(screen.getByRole("button", { name: "أوقِف عن البيع" }));

    await screen.findByText(/ستخرج من العرض: مجموعة السبت/);

    // ⛔ ONE call so far, and it carried no acknowledgement: the teacher is being
    // asked, and the server wrote nothing.
    expect(update).toHaveBeenCalledTimes(1);
    expect(update.mock.calls[0][1]).toMatchObject({ is_active: false });
    expect(update.mock.calls[0][1]).not.toHaveProperty("acknowledge_hidden_cohorts", true);

    fireEvent.click(screen.getByRole("button", { name: "أعرف، نفِّذ" }));

    await waitFor(() => expect(update).toHaveBeenCalledTimes(2));

    expect(update.mock.calls[1][1]).toMatchObject({
      is_active: false,
      acknowledge_hidden_cohorts: true,
    });
  });

  it("writes nothing at all when the teacher backs out", async () => {
    await open([UNPRICED_PLAN]);

    update.mockRejectedValueOnce(refusal());

    fireEvent.click(screen.getByRole("button", { name: "أوقِف عن البيع" }));

    await screen.findByText(/ستخرج من العرض: مجموعة السبت/);

    fireEvent.click(screen.getByRole("button", { name: "تراجع" }));

    await waitFor(() => expect(screen.queryByText(/ستخرج من العرض/)).toBeNull());

    expect(update).toHaveBeenCalledTimes(1);
  });
});

describe("editing a plan that is not on sale", () => {
  it("keeps it off, rather than switching it back on", async () => {
    /*
    | ⛔ THE ACTION READS AN ABSENT `is_active` AS `true`, and this form did not
    | send the field — so a save about the TITLE put a stopped plan back on sale,
    | silently, on the screen whose whole job is to say what is being sold.
    */
    await open([{ ...UNPRICED_PLAN, is_active: false }]);

    update.mockResolvedValue({ data: {} });

    fireEvent.click(screen.getByRole("button", { name: "تعديل" }));
    fireEvent.change(screen.getByLabelText(/اسم الباقة/), { target: { value: "الشهري الجديد" } });
    fireEvent.click(screen.getByRole("button", { name: "احفظ التعديل" }));

    await waitFor(() => expect(update).toHaveBeenCalledTimes(1));

    expect(update.mock.calls[0][1]).toMatchObject({ is_active: false });
  });
});
