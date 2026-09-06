import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import SettlementPage from "./page";
import type { TeacherStatement } from "@/lib/settlement";

/*
| ⚠️ THE ENDPOINT, THE ACTION, THE CLIENT FUNCTION AND THE TYPES ALL EXISTED —
| AND NOTHING CALLED THEM.
|
| `POST /settlement/rate-requests` has been live since spec 014, guarded by its
| own limiter, its own policy and two refusal rules; `settlement.requestRate` was
| written in `lib/settlement.ts`; this page already rendered the pending request
| and the effective rates. The one missing piece was a control, and in its place
| the empty state told the teacher to «تواصل مع إدارة المنصة» — a support ticket
| standing in for a route that was built.
|
| Same family as `writeBans.lift`: an Action and an endpoint and no screen.
*/

const statement = vi.fn();
const units = vi.fn();
const periods = vi.fn();
const requestRate = vi.fn();

vi.mock("@/lib/settlement", async (importOriginal) => ({
  // ⚠️ `importOriginal`, not a bare object: `toMinorUnits` is the conversion
  // under test through this screen, and stubbing it away would leave the case
  // asserting that a mock returns what the mock was told to return.
  ...(await importOriginal<typeof import("@/lib/settlement")>()),
  settlement: {
    statement: () => statement(),
    units: () => units(),
    periods: () => periods(),
    requestRate: (body: unknown) => requestRate(body),
    exportStatement: vi.fn(),
  },
}));

const STATEMENT: TeacherStatement = {
  period: {
    uuid: null,
    starts_on: "2026-09-01",
    ends_on: "2026-09-30",
    status: "open",
    status_label: "مفتوحة",
  },
  students_count: 3,
  units: { pending_package: 0, accrued: 2, disputed: 0, settled: 0, reversed: 0, by_type: {} },
  rates: [],
  pending_rate_request: null,
  currency: "QAR",
  gross_minor: 0,
  deductions: [],
  net_minor: 0,
  carried_in_minor: 0,
  next_payout_on: "2026-10-05",
};

beforeEach(() => {
  vi.clearAllMocks();
  statement.mockResolvedValue(STATEMENT);
  units.mockResolvedValue({ data: [] });
  periods.mockResolvedValue({ data: [] });
});

async function open() {
  render(<SettlementPage />);

  await waitFor(() => {
    expect(screen.getByText("اطلب تغيير سعرك")).toBeDefined();
  });
}

describe("asking for a rate", () => {
  it("sends MINOR units, converted from what the teacher typed", async () => {
    requestRate.mockResolvedValue({});

    await open();

    fireEvent.change(screen.getByLabelText(/السعر للحصة/), { target: { value: "150.5" } });
    fireEvent.click(screen.getByRole("button", { name: "أرسل الطلب" }));

    await waitFor(() => {
      expect(requestRate).toHaveBeenCalledWith({
        session_type: "individual",
        requested_amount_minor: 15050,
      });
    });
  });

  it("does not send an empty field, which would ask for a rate of zero", async () => {
    await open();

    fireEvent.click(screen.getByRole("button", { name: "أرسل الطلب" }));

    await waitFor(() => {
      expect(screen.getByText("لم يُرسَل الطلب")).toBeDefined();
    });

    expect(requestRate).not.toHaveBeenCalled();
  });

  it("shows the server's own refusal rather than a rule of its own", async () => {
    // ⚠️ THE FORM IS NOT HIDDEN OVER A PENDING REQUEST. The server refuses per
    // (type, subject, grade) and per time window, and this screen sees neither —
    // so a client-side guess would block a legitimate request and drift from the
    // real rule at the first change. The sentence, with its next-allowed date,
    // is the answer.
    // A real `ApiError`: `errorMessage()` shows a 422's own sentence and falls
    // back for everything else, so a plain Error here would assert the fallback.
    requestRate.mockRejectedValue(
      new ApiError("لديك طلب سعر قيد الاعتماد على هذا النطاق.", 422, {}),
    );

    await open();

    fireEvent.change(screen.getByLabelText(/السعر للحصة/), { target: { value: "200" } });
    fireEvent.click(screen.getByRole("button", { name: "أرسل الطلب" }));

    await waitFor(() => {
      expect(screen.getByText("لديك طلب سعر قيد الاعتماد على هذا النطاق.")).toBeDefined();
    });
  });

  it("reloads the statement on success, so the pending banner is the server's word", async () => {
    requestRate.mockResolvedValue({});

    await open();

    fireEvent.change(screen.getByLabelText(/السعر للحصة/), { target: { value: "90" } });
    fireEvent.click(screen.getByRole("button", { name: "أرسل الطلب" }));

    await waitFor(() => {
      expect(statement).toHaveBeenCalledTimes(2);
    });
  });
});
