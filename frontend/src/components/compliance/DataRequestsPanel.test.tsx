import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { DataRequestRecord } from "@/lib/compliance";

import { DataRequestsPanel } from "./DataRequestsPanel";

/**
 * FR-018 — the download button follows the SERVER's answer, not the status word.
 *
 * ⚠️ THIS IS THE DEFECT THE FILE EXISTS FOR. `status === "completed"` is the
 * obvious condition and it is wrong: `PruneExpiredExportsJob` deletes the archive
 * once its link expires and leaves the request completed for ever, because FR-026
 * wants the record that it was answered. A client deriving the button from the
 * status offers a download for a file that is gone, and the person gets a 404 with
 * no sentence attached to it. `is_downloadable` is the file AND its expiry
 * together, computed where both are known.
 *
 * ⚠️ AND NONE OF THIS IS REACHABLE FROM THE BACKEND SUITE. The API answers
 * correctly in every case below; what is being tested is whether the screen reads
 * the answer.
 */
const requests = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  download: vi.fn(),
}));

vi.mock("@/lib/compliance", () => ({ dataRights: requests }));

function record(overrides: Partial<DataRequestRecord> = {}): DataRequestRecord {
  return {
    uuid: "req-1",
    type: "export",
    status: "completed",
    due_at: "2026-09-20T00:00:00+00:00",
    completed_at: "2026-08-21T00:00:00+00:00",
    is_downloadable: true,
    export_expires_at: "2026-08-23T00:00:00+00:00",
    refusal_reason: null,
    ...overrides,
  };
}

describe("DataRequestsPanel", () => {
  beforeEach(() => {
    requests.list.mockReset();
    requests.create.mockReset();
    requests.download.mockReset();
  });

  it("offers the download when the server says the archive is still there", async () => {
    requests.list.mockResolvedValue({ data: [record()] });

    render(<DataRequestsPanel />);

    expect(await screen.findByRole("button", { name: "نزِّل الملفّ" })).toBeTruthy();
  });

  it("hides the download for a completed request whose archive has been cleaned up", async () => {
    requests.list.mockResolvedValue({
      data: [record({ is_downloadable: false, export_expires_at: null })],
    });

    render(<DataRequestsPanel />);

    // The row still renders — the record that it was answered is the point.
    expect(await screen.findByText("جاهز")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "نزِّل الملفّ" })).toBeNull();
  });

  /*
   * ⚠️ ONE OPEN REQUEST AT A TIME, SAID ON THE SCREEN RATHER THAN BY A REFUSAL.
   *
   * The server enforces it with a unique column, so a second tap produces one row
   * and no error — which means the person presses the button, nothing appears to
   * happen, and they press it again. Saying why is the whole job of this state.
   */
  it("says why the button is closed while a request is in flight", async () => {
    requests.list.mockResolvedValue({ data: [record({ status: "processing", is_downloadable: false })] });

    render(<DataRequestsPanel />);

    const button = await screen.findByRole("button", { name: "لديك طلبٌ قيد التنفيذ" });

    expect((button as HTMLButtonElement).disabled).toBe(true);
  });

  /*
   * ⚠️ A FAILED READ SHOWS A SENTENCE, NOT AN EMPTY LIST.
   *
   * `.catch(() => undefined)` on a fetch renders "you have never asked" to somebody
   * whose request is sitting there — the rule this repository already wrote down
   * after a swallowed error produced a permanently blank lesson page.
   */
  it("explains a failed read instead of rendering an empty list", async () => {
    requests.list.mockRejectedValue(new Error("boom"));

    render(<DataRequestsPanel />);

    await waitFor(() => {
      expect(screen.getByText("تعذّر تنفيذ الطلب")).toBeTruthy();
    });
  });
});
