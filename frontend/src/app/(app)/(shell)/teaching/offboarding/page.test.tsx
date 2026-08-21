import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { TeacherOffboarding } from "@/lib/compliance";

import OffboardingPage from "./page";

/**
 * The one screen in the product that ends a business.
 *
 * ⚠️ THE INTERESTING FAILURE IS NOT THE HAPPY PATH — it is a request that fires
 * from a single tap. This one notifies every student in the workspace and pulls
 * the public listing, and neither is reversible; the confirm step is what stands
 * between a misplaced click and a message on other people's phones.
 */
const client = vi.hoisted(() => ({
  show: vi.fn(),
  request: vi.fn(),
  content: vi.fn(),
}));

vi.mock("@/lib/compliance", () => ({ offboarding: client }));

function record(overrides: Partial<TeacherOffboarding> = {}): TeacherOffboarding {
  return {
    uuid: "off-1",
    status: "notice_period",
    status_label: "مهلة الإخطار",
    dues_cleared: true,
    students_notified_at: "2026-08-21T00:00:00+00:00",
    notice_ends_at: "2026-09-20T00:00:00+00:00",
    completed_at: null,
    content_export_ready: true,
    ...overrides,
  };
}

describe("OffboardingPage", () => {
  beforeEach(() => {
    client.show.mockReset();
    client.request.mockReset();
  });

  it("does not send the request on the first tap", async () => {
    client.show.mockResolvedValue({ data: null });

    render(<OffboardingPage />);

    fireEvent.click(await screen.findByRole("button", { name: "اطلبِ الخروج" }));

    expect(client.request).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "أكّد طلب الخروج" }));

    await waitFor(() => expect(client.request).toHaveBeenCalledTimes(1));
  });

  /*
   * ⚠️ A WORD, NEVER A NUMBER. A teacher's outstanding balance on a screen is the
   * platform's half of a rate, solvable from the other side across two package
   * sizes — which is what the allowlists on both the student's and the teacher's
   * payloads exist to stop. The server sends a boolean; this asserts the screen
   * cannot start showing a figure without somebody noticing.
   */
  it("says whether the books are square and never by how much", async () => {
    client.show.mockResolvedValue({ data: record({ dues_cleared: false }) });

    render(<OffboardingPage />);

    expect(await screen.findByText("قيد الحسم")).toBeTruthy();
    expect(screen.queryByText(/ريال|QAR/)).toBeNull();
  });

  /*
   * ⚠️ AND THERE IS NO COMPLETE BUTTON. Completing revokes access, ends every
   * membership and fixes the recordings' retention — FR-032 makes it the
   * officer's, conditional on money settled in both directions. A teacher
   * pressing their own would be signing off on their own settlement.
   */
  it("offers the teacher no way to finalise their own exit", async () => {
    client.show.mockResolvedValue({ data: record() });

    render(<OffboardingPage />);

    await screen.findByText("مهلة الإخطار");

    expect(screen.queryByRole("button", { name: /أتمِم/ })).toBeNull();
  });

  it("explains a failed read instead of rendering an empty page", async () => {
    client.show.mockRejectedValue(new Error("boom"));

    render(<OffboardingPage />);

    await waitFor(() => {
      expect(screen.getByText("تعذّر تنفيذ الإجراء")).toBeTruthy();
    });
  });
});
