import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import LeaderboardPage from "./page";
import { gamification } from "@/lib/gamification";

/*
 * ⚠️ GLOBALS ARE OFF IN THIS PROJECT'S VITEST CONFIG, so every helper is imported
 * by name, and the file sits under `src/` because the `include` glob is scoped
 * there — the default would swallow `e2e/*.spec.ts` and die inside Playwright's
 * runner.
 */
vi.mock("@/lib/gamification", () => ({
  gamification: { leaderboard: vi.fn(), leaderboardScopes: vi.fn() },
}));

const board = vi.mocked(gamification.leaderboard);
const scopes = vi.mocked(gamification.leaderboardScopes);

const EMPTY_BOARD = {
  scope: "platform",
  period: "2026-W34",
  level_band: 0,
  my_rank: null,
  my_points: null,
  entries: [],
};

describe("LeaderboardPage", () => {
  beforeEach(() => {
    board.mockReset();
    scopes.mockReset();
    board.mockResolvedValue(EMPTY_BOARD as never);
  });

  /*
   * ⚠️ THE DEFECT THIS TEST EXISTS FOR, AND IT IS THE FAMILY THAT SHIPPED THREE
   * TIMES ALREADY: a screen that does not know who is reading it offering an
   * action that depends on knowing.
   *
   * Every leaderboard scope is closed to a teacher — the cross-workspace three by
   * role, the other three for want of an enrolment they cannot hold in their own
   * workspace — and the nav entry carries no permission, so a teacher does reach
   * this page. It answered them with a refusal about a screen that is simply not
   * theirs, which reads as a broken page rather than as a rule.
   */
  it("tells a teacher whose screen this is instead of requesting a board", async () => {
    scopes.mockResolvedValue({ data: [] } as never);

    render(<LeaderboardPage />);

    await waitFor(() => expect(screen.getByText("اللوحات للطلاب")).not.toBeNull());

    // And the important half: no request was sent that would have come back 403.
    expect(board).not.toHaveBeenCalled();
  });

  /*
   * The five scopes that shipped reachable only by typing a URL.
   *
   * ⚠️ THE LABEL PREFIX IS THE WHOLE DISAMBIGUATION. A teacher and a course can
   * share a name — «الرياضيات» is a plausible label for a subject board AND for a
   * course — and two identical-looking options where one crosses workspaces and
   * the other does not is a picker nobody can use on purpose.
   */
  it("offers every board the server returns, each named by its kind", async () => {
    scopes.mockResolvedValue({
      data: [
        { scope: "platform", label: "المنصّة", kind: "platform" },
        { scope: "grade:secondary", label: "الثانوية", kind: "grade" },
        { scope: "subject:s-1", label: "الرياضيات", kind: "subject" },
        { scope: "teacher:w-1", label: "خالد", kind: "teacher" },
        { scope: "course:c-1", label: "الرياضيات", kind: "course" },
      ],
    } as never);

    render(<LeaderboardPage />);

    const select = (await screen.findByLabelText("اللوحة")) as HTMLSelectElement;
    const labels = Array.from(select.options).map((option) => option.text);

    expect(labels).toEqual([
      "المنصّة",
      "الصف: الثانوية",
      "المادة: الرياضيات",
      "المدرّس: خالد",
      "الكورس: الرياضيات",
    ]);
  });

  /*
   * A select with one option is a control that answers nothing.
   *
   * This is also the shape a student with no active enrolment gets, so the page
   * has to work with the picker absent rather than treat it as a failure.
   */
  it("hides the picker when the platform board is the only one", async () => {
    scopes.mockResolvedValue({
      data: [{ scope: "platform", label: "المنصّة", kind: "platform" }],
    } as never);

    render(<LeaderboardPage />);

    await waitFor(() => expect(board).toHaveBeenCalledWith("platform", "week"));
    expect(screen.queryByLabelText("اللوحة")).toBeNull();
  });

  /*
   * ⚠️ A FAILED PICKER MUST NOT BLANK THE PAGE. `platform` is open to every
   * student without any of this, so the fallback is the board that always exists
   * — the rule against showing a raw error is not a rule for showing nothing.
   */
  it("still shows the platform board when the picker fails to load", async () => {
    scopes.mockRejectedValue(new Error("network"));

    render(<LeaderboardPage />);

    await waitFor(() => expect(board).toHaveBeenCalledWith("platform", "week"));
    expect(screen.queryByText("اللوحات للطلاب")).toBeNull();
  });
});
