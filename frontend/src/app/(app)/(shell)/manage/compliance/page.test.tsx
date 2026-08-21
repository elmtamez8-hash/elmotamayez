import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { OfficerDataRequest } from "@/lib/compliance";

import ComplianceQueuePage from "./page";

/**
 * FR-026 — a refusal carries a reason, and the screen asks for it before the API
 * has to.
 *
 * ⚠️ THE SERVER ALREADY REFUSES AN EMPTY REASON WITH A 422, so this is not the
 * guard — it is the difference between a person understanding why the button did
 * nothing and a person pressing it again. The interesting failure is the opposite
 * one, though: a screen that let the refusal through and showed the raw validation
 * error, or one that disabled BOTH buttons on an empty field and made executing a
 * request impossible until somebody typed a refusal reason they did not mean.
 */
const queue = vi.hoisted(() => ({
  list: vi.fn(),
  execute: vi.fn(),
  refuse: vi.fn(),
  hold: vi.fn(),
}));

vi.mock("@/lib/compliance", () => ({ complianceQueue: queue }));

function record(overrides: Partial<OfficerDataRequest> = {}): OfficerDataRequest {
  return {
    uuid: "req-1",
    type: "erasure",
    status: "pending",
    due_at: "2026-09-20T00:00:00+00:00",
    completed_at: null,
    is_downloadable: false,
    export_expires_at: null,
    refusal_reason: null,
    subject: { uuid: "u-1", first_name: "زبيدة", last_name: "الخليفة" },
    ...overrides,
  };
}

describe("ComplianceQueuePage", () => {
  beforeEach(() => {
    queue.list.mockReset();
    queue.execute.mockReset();
    queue.refuse.mockReset();
  });

  it("names whose request is waiting", async () => {
    queue.list.mockResolvedValue({ data: [record()] });

    render(<ComplianceQueuePage />);

    expect(await screen.findByText(/زبيدة/)).toBeTruthy();
  });

  it("holds the refusal closed until a reason is written, and leaves execute open", async () => {
    queue.list.mockResolvedValue({ data: [record()] });

    render(<ComplianceQueuePage />);

    const refuse = await screen.findByRole("button", { name: "ارفض الطلب" });
    const execute = screen.getByRole("button", { name: "نفِّذ الطلب" });

    expect((refuse as HTMLButtonElement).disabled).toBe(true);
    /*
     * ⚠️ AND EXECUTE STAYS OPEN. Disabling both on an empty field is the obvious
     * one-line version and it makes running a request impossible until somebody
     * types a refusal reason they did not mean — the queue would deadlock on its
     * own validation.
     */
    expect((execute as HTMLButtonElement).disabled).toBe(false);
  });

  /*
   * ⚠️ A COMPLETED REQUEST OFFERS NEITHER BUTTON.
   *
   * The server answers 409 to a second execute — the claim is a conditional UPDATE
   * — so a screen that kept offering it would turn "already done" into an error
   * message for an officer who did nothing wrong.
   */
  it("offers no action on a request that has already been answered", async () => {
    queue.list.mockResolvedValue({ data: [record({ status: "completed" })] });

    render(<ComplianceQueuePage />);

    await screen.findByText("تمّ");

    expect(screen.queryByRole("button", { name: "نفِّذ الطلب" })).toBeNull();
    expect(screen.queryByRole("button", { name: "ارفض الطلب" })).toBeNull();
  });

  it("explains a failed read instead of showing an empty queue", async () => {
    queue.list.mockRejectedValue(new Error("boom"));

    render(<ComplianceQueuePage />);

    await waitFor(() => {
      expect(screen.getByText("تعذّر تنفيذ الإجراء")).toBeTruthy();
    });
  });
});
