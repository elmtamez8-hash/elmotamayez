import { readFileSync } from "node:fs";
import { join } from "node:path";

import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

import { AssignmentForm, EMPTY_ASSIGNMENT, assignmentPayload } from "./AssignmentForm";

const create = vi.fn();
const update = vi.fn();

vi.mock("@/lib/assignments", () => ({
  assignments: {
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
  },
}));

/*
| ⚠️ THE PAYLOAD IS CHECKED AGAINST THE FORM REQUEST ON DISK, NOT AGAINST A LIST
| WRITTEN HERE. A second list would agree with this form by construction; what
| has to be caught is the two sides drifting — the day a rule is renamed in PHP
| (as `course_id` → `course_uuid` was for this screen) or a key is misspelled
| here. Laravel's `validated()` silently DROPS any key without a rule, so a
| misspelled field would save as null with a 201 and nobody would know.
*/
function formRequestKeys(): string[] {
  const path = join(
    process.cwd(),
    "..",
    "backend",
    "app",
    "Modules",
    "Assessments",
    "Http",
    "Requests",
    "SaveAssignmentRequest.php",
  );
  const source = readFileSync(path, "utf8");
  const rules = source.slice(source.indexOf("public function rules()"), source.indexOf("public function actionData("));

  return [...rules.matchAll(/^\s*'([a-z_]+)' => \[/gm)].map((match) => match[1]);
}

describe("assignmentPayload", () => {
  it("sends only keys the form request has a rule for", () => {
    const keys = formRequestKeys();

    // The control: a regex that matched nothing would pass the loop below.
    expect(keys).toContain("title");
    expect(keys).toContain("course_uuid");

    for (const key of Object.keys(assignmentPayload(EMPTY_ASSIGNMENT))) {
      expect(keys).toContain(key);
    }
  });

  it("converts the local deadline to an absolute instant", () => {
    const body = assignmentPayload({ ...EMPTY_ASSIGNMENT, title: "واجب", dueAt: "2026-10-01T18:30" });

    expect(body.due_at).toBe(new Date("2026-10-01T18:30").toISOString());
  });

  it("sends no course for «كل طلابي» and no penalty unless the policy is a penalty", () => {
    const body = assignmentPayload({ ...EMPTY_ASSIGNMENT, penaltyPerDay: "20", latePolicy: "accept" });

    expect(body.course_uuid).toBeNull();
    expect(body.late_penalty_pct_per_day).toBe(0);
    expect(body.due_at).toBeNull();
  });
});

describe("AssignmentForm", () => {
  beforeEach(() => {
    create.mockReset();
    update.mockReset();
  });

  it("creates a draft with the chosen course", async () => {
    create.mockResolvedValue({ data: {} });
    const onSaved = vi.fn();

    render(
      <AssignmentForm
        editing={null}
        courses={[{ uuid: "c-1", title: "الفيزياء" }]}
        onSaved={onSaved}
        onCancel={() => undefined}
      />,
    );

    fireEvent.change(screen.getByLabelText(/العنوان/), { target: { value: "واجب الفصل الأول" } });
    fireEvent.change(screen.getByLabelText("الكورس"), { target: { value: "c-1" } });
    fireEvent.click(screen.getByRole("button", { name: "احفظ مسوّدة" }));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    expect(create).toHaveBeenCalledWith(
      expect.objectContaining({ title: "واجب الفصل الأول", course_uuid: "c-1", submission_type: "text" }),
    );
  });

  it("puts a 422 under the field it belongs to", async () => {
    create.mockRejectedValue(
      new ApiError("invalid", 422, {
        message: "invalid",
        errors: { title: ["حقل العنوان مطلوب."] },
      }),
    );

    render(<AssignmentForm editing={null} courses={[]} onSaved={() => undefined} onCancel={() => undefined} />);

    fireEvent.click(screen.getByRole("button", { name: "احفظ مسوّدة" }));

    expect(await screen.findByText("حقل العنوان مطلوب.", { selector: "#assignment_title-error" })).toBeTruthy();
  });
});
