import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ReportReviewButton } from "./ReportReviewButton";

/*
 * «إبلاغ» under a public review (FR-034).
 *
 * The route existed and nothing reached it: the profile carried no review uuid
 * and the tab had no button. What is measured is the request itself — the uuid
 * in the path, the optional reason in the body, and nothing sent before the
 * reader confirms — plus the two audiences: a visitor is offered nothing, since
 * the route is behind sign-in.
 */
const api = vi.hoisted(() => ({ post: vi.fn() }));
const token = vi.hoisted(() => ({ present: true }));

vi.mock("@/lib/api", () => ({
  api,
  fieldErrors: () => ({}),
  hasAuthToken: () => token.present,
  ApiError: class ApiError extends Error {},
}));

describe("ReportReviewButton", () => {
  beforeEach(() => {
    api.post.mockReset();
    token.present = true;
  });

  it("asks first, then posts the review uuid with the reason, and says what happened", async () => {
    api.post.mockResolvedValue({ message: "وصلنا بلاغك، وسيطّلع عليه فريق المنصّة." });

    render(<ReportReviewButton reviewUuid="r-1" />);

    await userEvent.click(await screen.findByRole("button", { name: "إبلاغ" }));
    expect(api.post).not.toHaveBeenCalled();

    // `fireEvent`, not `userEvent.type`: one change event is the whole claim
    // here, and typing twelve characters one by one is what timed out under load.
    fireEvent.change(screen.getByLabelText("السبب (اختياري)"), { target: { value: "  كلام مسيء  " } });
    await userEvent.click(screen.getByRole("button", { name: "أرسل البلاغ" }));

    await waitFor(() =>
      expect(api.post.mock.calls).toEqual([["/reviews/r-1/report", { reason: "كلام مسيء" }]]),
    );
    expect((await screen.findByRole("status")).textContent).toContain("وصلنا بلاغك");
  });

  it("sends no reason key when the reader gives none", async () => {
    api.post.mockResolvedValue({ message: "ok" });

    render(<ReportReviewButton reviewUuid="r-2" />);

    await userEvent.click(await screen.findByRole("button", { name: "إبلاغ" }));
    await userEvent.click(screen.getByRole("button", { name: "أرسل البلاغ" }));

    await waitFor(() => expect(api.post.mock.calls).toEqual([["/reviews/r-2/report", {}]]));
  });

  it("offers nothing to a visitor who is not signed in", async () => {
    token.present = false;

    render(<ReportReviewButton reviewUuid="r-1" />);

    // Give the mount effect its turn before asserting the absence.
    await waitFor(() => expect(screen.queryByRole("button", { name: "إبلاغ" })).toBeNull());
    expect(api.post).not.toHaveBeenCalled();
  });

  it("never shows a raw error", async () => {
    api.post.mockRejectedValue(new Error("Request failed with status 404"));

    render(<ReportReviewButton reviewUuid="r-gone" />);

    await userEvent.click(await screen.findByRole("button", { name: "إبلاغ" }));
    await userEvent.click(screen.getByRole("button", { name: "أرسل البلاغ" }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.queryByText(/status 404/)).toBeNull();
  });
});
