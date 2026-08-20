import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { RedeemButton } from "./RedeemButton";
import { gamification } from "@/lib/gamification";
import { ApiError } from "@/lib/api";

/*
 * ⚠️ GLOBALS ARE OFF IN THIS PROJECT'S VITEST CONFIG, so every helper is
 * imported by name. And the file is `*.test.tsx` under `src/` because the
 * `include` glob is scoped there — the default would swallow `e2e/*.spec.ts` and
 * die inside Playwright's runner.
 */
vi.mock("@/lib/gamification", () => ({
  gamification: { redeem: vi.fn() },
}));

const redeem = vi.mocked(gamification.redeem);

describe("RedeemButton", () => {
  beforeEach(() => {
    redeem.mockReset();
  });

  /*
   * ⚠️ THE DEFECT THIS COMPONENT EXISTS FOR.
   *
   * The same family shipped in spec 008 — a second tap turning a right answer
   * into a zero on a graded paper — and here it would take a student's coins
   * twice for one reward. The server's claim is atomic and would refuse the
   * second attempt, but "the server catches it" is not an argument for sending
   * it: the second request is a second chance to be told something confusing.
   */
  it("sends one request however fast the button is clicked twice", async () => {
    let resolve: (value: unknown) => void = () => {};
    redeem.mockReturnValue(new Promise((done) => { resolve = done; }) as never);

    render(<RedeemButton rewardUuid="r-1" />);

    const button = screen.getByRole("button");
    fireEvent.click(button);
    fireEvent.click(button);
    fireEvent.click(button);

    expect(redeem).toHaveBeenCalledTimes(1);

    resolve({ data: {} });
    await waitFor(() => expect((button as HTMLButtonElement).disabled).toBe(false));
  });

  it("calls back only after the request succeeds", async () => {
    redeem.mockResolvedValue({ data: {} } as never);
    const onRedeemed = vi.fn();

    render(<RedeemButton rewardUuid="r-1" onRedeemed={onRedeemed} />);
    fireEvent.click(screen.getByRole("button"));

    await waitFor(() => expect(onRedeemed).toHaveBeenCalledTimes(1));
  });

  /*
   * ⚠️ THE REFUSAL IS A SENTENCE, NOT A FIELD ERROR. The 422 carries
   * `{message, code}` rather than a validation-errors shape, so it goes through
   * `userMessage()`; `fieldErrors()` would find nothing and render nothing —
   * a student clicking "redeem" and seeing absolutely no response.
   */
  it("shows the server's Arabic refusal rather than nothing", async () => {
    redeem.mockRejectedValue(
      new ApiError("بلغت هذه المكافأة سقفها الشهري.", 422, {
        message: "بلغت هذه المكافأة سقفها الشهري.",
        code: "monthly_cap_reached",
      }),
    );

    render(<RedeemButton rewardUuid="r-1" />);
    fireEvent.click(screen.getByRole("button"));

    expect((await screen.findByRole("alert")).textContent).toContain("بلغت هذه المكافأة سقفها الشهري.");
  });

  it("does not send at all when the student cannot afford it", () => {
    render(<RedeemButton rewardUuid="r-1" disabled />);

    fireEvent.click(screen.getByRole("button"));

    expect(redeem).not.toHaveBeenCalled();
  });
});
