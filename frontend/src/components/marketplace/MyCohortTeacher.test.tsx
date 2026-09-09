import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| ⛔ A TEACHER IS NEVER OFFERED «اشترك في هذه المجموعة» — REPORTED 2026-09-08.
|
| The public page of a course showed its own owner a subscribe button, and all
| three purchase doors accepted it. The doors refuse now, on the server, with
| their own suite (`TeacherNeverBuysTest`). This file measures the OTHER half:
| a control that is offered and then refused is a payment screen ending in a
| sentence, which is a promise the product does not keep.
|
| ⚠️ THIS IS NOT THE GUARD AND MUST NEVER BE READ AS ONE. Hiding a button
| protects nobody — the server does. Same division `UnlessMyCohort` already drew
| for the reader's own group.
|
| ⚠️ AND ONLY A COMPONENT TEST CAN SEE IT. The backend does not know the button
| exists, and Playwright would need a live session for a teacher on a public
| page. `npm test` needs neither and finishes in seconds.
*/
const me = vi.fn();
const forCourse = vi.fn();
let signedIn = true;

vi.mock("@/lib/api", () => ({
  auth: { me: () => me() },
  hasAuthToken: () => signedIn,
}));

vi.mock("@/lib/cohorts", () => ({
  cohorts: { forCourse: () => forCourse() },
}));

const { MyCohortProvider, UnlessMyCohort } = await import("./MyCohort");

const COHORT = "aaaa0000-0000-4000-8000-000000000001";
const COURSE = "cccc0000-0000-4000-8000-000000000001";

function renderButton() {
  return render(
    <MyCohortProvider courseUuid={COURSE}>
      <UnlessMyCohort cohortUuid={COHORT}>
        <button type="button">اشترك في هذه المجموعة</button>
      </UnlessMyCohort>
    </MyCohortProvider>,
  );
}

describe("UnlessMyCohort · a teacher is never offered the subscribe button", () => {
  beforeEach(() => {
    signedIn = true;
    me.mockReset();
    forCourse.mockReset();
    forCourse.mockResolvedValue({ membership: null });
  });

  it("hides it from a teacher-side account", async () => {
    // `workplaces()` returns an empty list for a student or a guardian outright,
    // so a non-empty one IS a teacher or an assistant. No new field.
    me.mockResolvedValue({ workspaces: [{ uuid: "w1", name: "أكاديميّة خالد" }] });

    renderButton();

    await waitFor(() => {
      expect(screen.queryByText("اشترك في هذه المجموعة")).toBeNull();
    });
  });

  it("CONTROL — still shows it to a student, or the assertion above is vacuous", async () => {
    /*
    | ⛔ MANDATORY. The case above asserts an ABSENCE, which is also what a
    | throwing mock, a wrong import or a provider that renders nothing produces.
    | The bug this control guards against is the expensive one: hiding the
    | subscribe button from every student is the reported defect's mirror image
    | and costs the platform its revenue.
    */
    me.mockResolvedValue({ workspaces: [] });

    renderButton();

    expect(await screen.findByText("اشترك في هذه المجموعة")).toBeTruthy();
  });

  it("CONTROL — shows it to a signed-out visitor and asks nothing at all", async () => {
    // The crawler and the logged-out buyer: zero requests from this page, which
    // is why `hasAuthToken()` gates the whole effect rather than each fetch.
    signedIn = false;

    renderButton();

    expect(await screen.findByText("اشترك في هذه المجموعة")).toBeTruthy();
    expect(me).not.toHaveBeenCalled();
    expect(forCourse).not.toHaveBeenCalled();
  });

  it("shows it when /auth/me fails — an unknown reader is not treated as a teacher", async () => {
    /*
    | A swallowed failure must fall back to the STATUS QUO, not to hiding. The
    | server refuses a teacher either way, so the cost of guessing wrong here is
    | one refusal sentence; the cost of the opposite default is a student who
    | cannot buy because a request timed out.
    */
    me.mockRejectedValue(new Error("network"));

    renderButton();

    expect(await screen.findByText("اشترك في هذه المجموعة")).toBeTruthy();
  });
});
