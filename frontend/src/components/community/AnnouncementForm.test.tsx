import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";

import { AnnouncementForm } from "./AnnouncementForm";

/*
| The three scopes, and the flag that says what it does (spec 010 · US6).
|
| ⚠️ WHAT IS MEASURED HERE IS WHAT NO BACKEND TEST CAN SEE. `AnnouncementScopeTest`
| proves the server sends an announcement to its scope and to nobody else; that
| suite would stay green over a form which offers a course picker for a session
| scope, or which carries a stale uuid from the previous selection into the new
| one — both of which publish a perfectly valid announcement to the wrong class.
|
| ⚠️ AND THE URGENT FLAG'S SENTENCE IS ASSERTED, NOT ITS CHECKBOX. A flag whose
| effect is unstated is a flag every teacher ticks, and «عاجل» exists precisely to
| be the exception — it reaches a student mid-focus-session, which is the one
| behaviour the mandatory type actually buys on the bell.
*/

const courses = [
  { uuid: "course-1", title: "الرياضيات" },
  { uuid: "course-2", title: "الفيزياء" },
];

const sessions = [{ uuid: "session-1", title: "حصة الأحد" }];

function setup(onSubmit = vi.fn()) {
  render(<AnnouncementForm onSubmit={onSubmit} courses={courses} sessions={sessions} />);

  return onSubmit;
}

describe("AnnouncementForm", () => {
  it("sends a workspace-wide notice with no target", () => {
    const onSubmit = setup();

    fireEvent.change(screen.getByLabelText(/نصّ الإعلان/), {
      target: { value: "حصة الغد الساعة الخامسة." },
    });
    fireEvent.click(screen.getByRole("button", { name: "حفظ" }));

    expect(onSubmit).toHaveBeenCalledWith({
      body: "حصة الغد الساعة الخامسة.",
      scope: "all",
      scope_uuid: null,
      is_urgent: false,
    });
  });

  it("asks for a course before it will send a course notice", () => {
    const onSubmit = setup();

    fireEvent.change(screen.getByLabelText(/نصّ الإعلان/), { target: { value: "تنبيه" } });
    fireEvent.change(screen.getByLabelText("من يصله"), { target: { value: "course" } });

    // Refused, not sent-with-nothing: the server would answer 422, and a form
    // that submits a request it knows is incomplete is a round trip spent to
    // learn something it could say immediately.
    expect((screen.getByRole("button", { name: "حفظ" }) as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText("الكورس"), { target: { value: "course-2" } });
    fireEvent.click(screen.getByRole("button", { name: "حفظ" }));

    expect(onSubmit).toHaveBeenCalledWith({
      body: "تنبيه",
      scope: "course",
      scope_uuid: "course-2",
      is_urgent: false,
    });
  });

  it("offers sessions for a session notice and courses for a course one", () => {
    setup();

    fireEvent.change(screen.getByLabelText("من يصله"), { target: { value: "session" } });

    expect(screen.getByLabelText("الحصة")).toBeTruthy();
    expect(screen.getByRole("option", { name: "حصة الأحد" })).toBeTruthy();
    expect(screen.queryByRole("option", { name: "الفيزياء" })).toBeNull();
  });

  it("drops the chosen target when the scope changes", () => {
    const onSubmit = setup();

    fireEvent.change(screen.getByLabelText(/نصّ الإعلان/), { target: { value: "تنبيه" } });
    fireEvent.change(screen.getByLabelText("من يصله"), { target: { value: "course" } });
    fireEvent.change(screen.getByLabelText("الكورس"), { target: { value: "course-1" } });

    // ⚠️ THE DEFECT THIS GUARDS IS A VALID REQUEST TO THE WRONG PEOPLE. A course
    // uuid left behind under a session scope is refused by the server — but a
    // SESSION uuid left behind under a course scope would not be, and neither
    // would the reverse on an installation where the two ever collide. The form
    // clears it rather than relying on that.
    fireEvent.change(screen.getByLabelText("من يصله"), { target: { value: "session" } });

    expect((screen.getByRole("button", { name: "حفظ" }) as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText("الحصة"), { target: { value: "session-1" } });
    fireEvent.click(screen.getByRole("button", { name: "حفظ" }));

    expect(onSubmit).toHaveBeenCalledWith({
      body: "تنبيه",
      scope: "session",
      scope_uuid: "session-1",
      is_urgent: false,
    });
  });

  it("says who each scope reaches, in words", () => {
    setup();

    expect(screen.getByTestId("scope-hint").textContent).toContain("كلّ طالب مسجَّل عندك الآن");

    fireEvent.change(screen.getByLabelText("من يصله"), { target: { value: "session" } });

    expect(screen.getByTestId("scope-hint").textContent).toContain("من حجز مقعداً في هذه الحصة");
  });

  it("declares what urgent does before it is ticked", () => {
    const onSubmit = setup();

    expect(screen.getByTestId("urgent-effect").textContent).toContain("ينتظر انتهاء جلسة التركيز");

    fireEvent.click(screen.getByLabelText("إعلان عاجل"));

    expect(screen.getByTestId("urgent-effect").textContent).toContain("حتى وهو في جلسة تركيز");

    fireEvent.change(screen.getByLabelText(/نصّ الإعلان/), { target: { value: "أُلغيت الحصة" } });
    fireEvent.click(screen.getByRole("button", { name: "حفظ" }));

    expect(onSubmit).toHaveBeenCalledWith(
      expect.objectContaining({ is_urgent: true, scope: "all" }),
    );
  });

  it("hides the scope on an edit, because it cannot move", () => {
    render(
      <AnnouncementForm
        onSubmit={vi.fn()}
        courses={courses}
        sessions={sessions}
        initial={{ body: "النصّ الأول", scope: "course", scope_uuid: "course-1" }}
        scopeLocked
      />,
    );

    // The audience has already been told. Offering to move it would be offering
    // to leave one group holding a message meant for another.
    expect(screen.queryByLabelText("من يصله")).toBeNull();
    expect((screen.getByLabelText(/نصّ الإعلان/) as HTMLTextAreaElement).value).toBe("النصّ الأول");

    // ⚠️ AND IT CAN ACTUALLY BE SAVED. With the picker hidden the chosen uuid is
    // empty, so a "needs a target" check that ignored the lock would disable the
    // button for ever on exactly the announcements FR-047 exists to correct —
    // and the screen would look finished.
    expect((screen.getByRole("button", { name: "حفظ" }) as HTMLButtonElement).disabled).toBe(false);
  });
});
