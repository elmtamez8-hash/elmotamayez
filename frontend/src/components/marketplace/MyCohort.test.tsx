import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MyCohortBadge, MyCohortLink, MyCohortProvider, UnlessMyCohort } from "./MyCohort";

const hasAuthToken = vi.fn();
const forCourse = vi.fn();

/*
  ⚠️ `auth.me` IS MOCKED HERE TOO, and an empty `workspaces` is what makes these
  cases about a STUDENT. Spec 033 added a second read to this provider — «does
  the reader teach?», answered by `UserResource::workplaces()` — and
  `UnlessMyCohort` hides the subscribe button outright for a teacher. Without
  this the module has no `auth` export and every case here dies in the effect.
*/
vi.mock("@/lib/api", () => ({
  hasAuthToken: () => hasAuthToken(),
  auth: { me: async () => ({ workspaces: [] }) },
}));

vi.mock("@/lib/cohorts", () => ({
  cohorts: { forCourse: (uuid: string) => forCourse(uuid) },
}));

const MINE = "cohort-saturday";
const THEIRS = "cohort-sunday";

async function show() {
  return act(async () => {
    render(
      <MyCohortProvider courseUuid="course-1">
        <div data-testid="mine">
          <MyCohortBadge cohortUuid={MINE} />
          <UnlessMyCohort cohortUuid={MINE}>
            <a href="/subscribe">اشترك في هذه المجموعة</a>
          </UnlessMyCohort>
          <MyCohortLink courseUuid="course-1" cohortUuid={MINE} />
        </div>
        <div data-testid="theirs">
          <MyCohortBadge cohortUuid={THEIRS} />
          <UnlessMyCohort cohortUuid={THEIRS}>
            <a href="/subscribe">اشترك في هذه المجموعة</a>
          </UnlessMyCohort>
          <MyCohortLink courseUuid="course-1" cohortUuid={THEIRS} />
        </div>
      </MyCohortProvider>,
    );
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("MyCohort", () => {
  it("marks the reader's own group and no other", async () => {
    hasAuthToken.mockReturnValue(true);
    forCourse.mockResolvedValue({ membership: { cohort_uuid: MINE } });

    await show();

    expect(screen.getAllByText("مجموعتك")).toHaveLength(1);
    expect(screen.getByTestId("mine").textContent).toContain("مجموعتك");
    expect(screen.getByTestId("theirs").textContent).not.toContain("مجموعتك");
  });

  it("takes the subscribe invitation off the group they are already in", async () => {
    hasAuthToken.mockReturnValue(true);
    forCourse.mockResolvedValue({ membership: { cohort_uuid: MINE } });

    await show();

    /*
      ⚠️ «مجموعتك» beside «اشترك في هذه المجموعة» is an invitation to buy a place
      they already hold — and the server refuses it only AFTER a payment screen.
      It is hidden rather than disabled, the same rule the list follows for a full
      or closed group: a dead control is a promise the product will not keep.
    */
    // ⚠️ Named by its TEXT, not by «the card has a link»: the member's card
    // legitimately carries «افتح مجموعتك», and a bare `querySelector("a")` would
    // start passing the day that link was removed.
    expect(screen.getByTestId("mine").textContent).not.toContain("اشترك في هذه المجموعة");
    expect(screen.getByTestId("theirs").textContent).toContain("اشترك في هذه المجموعة");
  });

  it("opens the group it names, and only on the reader's own card", async () => {
    hasAuthToken.mockReturnValue(true);
    forCourse.mockResolvedValue({ membership: { cohort_uuid: MINE } });

    await show();

    /*
      ⚠️ A PUBLIC PAGE THAT ONLY SELLS IS A DEAD END FOR THE PERSON WHO ALREADY
      BOUGHT. A student arriving from a search result or their own bookmark read
      «اشترك» about a group they sit in, with nothing on the page leading to it.
      `?tab=roster` lands on the group rather than on the curriculum with the
      group one more click away.
    */
    const link = screen.getByRole("link", { name: "افتح مجموعتك" });

    expect(link.getAttribute("href")).toBe("/enrollments/course-1?tab=roster");
    expect(screen.getByTestId("theirs").textContent).not.toContain("افتح مجموعتك");
  });

  it("asks nothing at all for a guest", async () => {
    hasAuthToken.mockReturnValue(false);

    await show();

    // ⚠️ This is a PUBLIC, crawlable page: the overwhelming majority of its
    // readers are not signed in, and a request per visit for an answer that is
    // always «nobody» is a request that should never leave the browser.
    expect(forCourse).not.toHaveBeenCalled();
    expect(screen.queryByText("مجموعتك")).toBeNull();
    expect(screen.queryByRole("link", { name: "افتح مجموعتك" })).toBeNull();
    expect(screen.getByTestId("mine").textContent).toContain("اشترك في هذه المجموعة");
  });

  it("asks once for the whole list, never once per card", async () => {
    hasAuthToken.mockReturnValue(true);
    forCourse.mockResolvedValue({ membership: { cohort_uuid: MINE } });

    await show();

    // Two cards above; a badge that fetched for itself would make this two.
    expect(forCourse).toHaveBeenCalledTimes(1);
    expect(forCourse).toHaveBeenCalledWith("course-1");
  });

  it("marks nothing when the reader is in no group here", async () => {
    hasAuthToken.mockReturnValue(true);
    forCourse.mockResolvedValue({ membership: null });

    await show();

    expect(screen.queryByText("مجموعتك")).toBeNull();
    expect(screen.getByTestId("mine").textContent).toContain("اشترك في هذه المجموعة");
  });

  it("leaves the page exactly as it was when the read is refused", async () => {
    hasAuthToken.mockReturnValue(true);
    forCourse.mockRejectedValue(new Error("403"));

    await show();

    // A signed-in reader who is not enrolled is refused by that route, and
    // «you are in none of these» is what an unmarked list already says.
    expect(screen.queryByText("مجموعتك")).toBeNull();
    expect(screen.getByTestId("mine").textContent).toContain("اشترك في هذه المجموعة");
  });
});
