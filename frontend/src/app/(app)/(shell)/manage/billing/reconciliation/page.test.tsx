import { render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { CreditReconciliationRun } from "@/lib/billing";

import CreditReconciliationPage from "./page";

/**
 * ⛔ THE NIGHTLY CREDIT RECONCILIATION HAD NO SCREEN AT ALL, and the two states
 * this file is really about are the ones a screen gets wrong quietly.
 *
 * `ReconcileCreditBalancesJob` writes a row every night; its reader's docblock
 * says «a reconciliation nobody notices has stopped is a reconciliation that is
 * not happening». Two shapes of that:
 *
 *   · «it has never run» rendered as «nothing was found» — a reassurance about a
 *     check that did not happen;
 *   · a run from four days ago rendered exactly like last night's — the sweep
 *     stopped, the page still looks calm, and the numbers are stale.
 *
 * Neither is visible from the backend, and neither would fail a type check.
 */
const billing = vi.hoisted(() => ({ creditReconciliation: vi.fn() }));

vi.mock("@/lib/billing", () => ({ billing }));

function run(overrides: Partial<CreditReconciliationRun> = {}): CreditReconciliationRun {
  return {
    ran_at: new Date(Date.now() - 3 * 3_600_000).toISOString(),
    balances_checked: 412,
    sessions_checked: 1908,
    findings_count: 0,
    findings: [],
    ...overrides,
  };
}

describe("CreditReconciliationPage", () => {
  beforeEach(() => {
    billing.creditReconciliation.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("says the sweep has not run rather than that nothing was found", async () => {
    // The whole point of the API answering `null` instead of an empty run.
    billing.creditReconciliation.mockResolvedValue({ data: null });

    render(<CreditReconciliationPage />);

    expect(await screen.findByText(/لم تُشغَّل المطابقة بعد/)).toBeTruthy();
    expect(screen.queryByText(/الدفتر متّسق/)).toBeNull();
  });

  it("says the ledger is consistent when a fresh run found nothing", async () => {
    // The positive control for the case above: the two empty screens must not be
    // the same screen.
    billing.creditReconciliation.mockResolvedValue({ data: run() });

    render(<CreditReconciliationPage />);

    expect(await screen.findByText(/الدفتر متّسق/)).toBeTruthy();
    expect(screen.queryByText(/المطابقة متوقّفة/)).toBeNull();
  });

  it("warns that the sweep stopped when the last run is more than a day old", async () => {
    billing.creditReconciliation.mockResolvedValue({
      data: run({ ran_at: new Date(Date.now() - 4 * 24 * 3_600_000).toISOString() }),
    });

    render(<CreditReconciliationPage />);

    // Without this the page shows four-day-old numbers under a reassuring
    // «الدفتر متّسق» and nothing anywhere says the job has not run.
    expect(await screen.findByText(/المطابقة متوقّفة/)).toBeTruthy();
  });

  it("does not warn on a run from this morning", async () => {
    // The other side of the threshold, so a stale banner that fires on every
    // healthy night — which is how a banner gets ignored — fails here.
    billing.creditReconciliation.mockResolvedValue({
      data: run({ ran_at: new Date(Date.now() - 20 * 3_600_000).toISOString() }),
    });

    render(<CreditReconciliationPage />);

    expect(await screen.findByText(/أرصدة فُحصت/)).toBeTruthy();
    expect(screen.queryByText(/المطابقة متوقّفة/)).toBeNull();
  });

  it("shows the true total beside a capped sample", async () => {
    /*
     * The job stores 200 findings at most and `findings_count` is the real
     * number. A table of three rows with no note is a page claiming there are
     * three problems when there are nine thousand.
     */
    billing.creditReconciliation.mockResolvedValue({
      data: run({
        findings_count: 9000,
        findings: [
          {
            check: "session_seats",
            workspace_id: 3,
            class_session_id: 77,
            expected: 5,
            actual: 3,
          },
        ],
      }),
    });

    render(<CreditReconciliationPage />);

    expect(await screen.findByText(/القائمة عيّنة وليست كلّ شيء/)).toBeTruthy();
    expect(screen.getByText(/حصّة حوسبت بعدد قيود لا يطابق مقاعدها/)).toBeTruthy();
    expect(screen.getByText(/المتوقّع 5 · المسجَّل 3/)).toBeTruthy();
  });

  it("never shows a raw error", async () => {
    // A 403 here is a person who may not read the platform's ledger; the rule
    // against raw errors is not a rule for showing nothing.
    billing.creditReconciliation.mockRejectedValue(new Error("Request failed with status 403"));

    render(<CreditReconciliationPage />);

    expect(await screen.findByText(/تعذّر عرض التقرير/)).toBeTruthy();
    expect(screen.queryByText(/status 403/)).toBeNull();
  });
});
