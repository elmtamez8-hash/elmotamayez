import { act, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import PaymentAuditPage from "./page";
import type { PaymentAuditEntry } from "@/lib/payment-audit";

/*
| The audit list is the ONLY way into one payment's chain.
|
| ⚠️ The decisions worth opening — approve, reject, receipt — are logged on the
| ORDER, and the chain is addressed by the PAYMENT. So the link reads
| `payment_uuid`, never `subject_uuid`: an order row linked by its subject would
| open a 404 for every approval on the page.
*/

const list = vi.fn();

vi.mock("@/lib/payment-audit", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/payment-audit")>()),
  paymentAudit: { list: () => list(), chain: vi.fn() },
}));

const entry = (overrides: Partial<PaymentAuditEntry>): PaymentAuditEntry => ({
  event: "approved",
  subject_type: "order",
  subject_uuid: "order-1",
  payment_uuid: "pay-1",
  actor_name: "موظف المالية",
  ip_address: "10.0.0.1",
  user_agent: null,
  properties: {},
  occurred_at: "2026-09-20T10:00:00Z",
  ...overrides,
});

beforeEach(() => {
  vi.clearAllMocks();
});

async function open() {
  await act(async () => {
    render(<PaymentAuditPage />);
  });
}

describe("the payments audit list", () => {
  it("links an order row to its PAYMENT's chain, not to the order", async () => {
    list.mockResolvedValue({ data: [entry({})] });

    await open();

    const link = screen.getByRole("link", { name: "تتبّع الدفعة" });

    expect(link.getAttribute("href")).toBe("/manage/payments/audit/pay-1");
    expect(screen.getByText("اعتماد دفعة")).toBeDefined();
  });

  it("offers no link where there is no payment to open", async () => {
    list.mockResolvedValue({
      data: [entry({ event: "credit_limit.changed", subject_type: "balance", subject_uuid: "bal-1", payment_uuid: null })],
    });

    await open();

    expect(screen.queryByRole("link", { name: "تتبّع الدفعة" })).toBeNull();

    const row = screen.getByText("تعديل الحد الائتماني").closest("tr");

    expect(row).not.toBeNull();
    expect(within(row as HTMLElement).getByText("حساب رصيد")).toBeDefined();
  });

  it("names the system, not a person, on an act nobody took", async () => {
    list.mockResolvedValue({ data: [entry({ actor_name: null })] });

    await open();

    expect(screen.getByText("النظام")).toBeDefined();
  });
});
