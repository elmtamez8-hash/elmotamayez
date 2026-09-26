import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import PaymentAuditChainPage from "./page";
import { ApiError } from "@/lib/api";
import type { PaymentAuditChain } from "@/lib/payment-audit";

/*
| One payment followed to the end — the page `GET /admin/payments/audit/{tx}`
| never had.
|
| ⚠️ A NULL IS «NOT PART OF THIS SALE», NEVER ZERO: a course order buys no
| credits, and «٠ حصص» there would read as a sale that minted nothing.
*/

const chain = vi.fn();

vi.mock("next/navigation", () => ({
  useParams: () => ({ transaction: "pay-1" }),
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

vi.mock("@/lib/payment-audit", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/payment-audit")>()),
  paymentAudit: { list: vi.fn(), chain: (uuid: string) => chain(uuid) },
}));

const creditChain = (overrides: Partial<PaymentAuditChain> = {}): PaymentAuditChain => ({
  payment: {
    uuid: "pay-1",
    status: "captured",
    provider: "manual",
    method: "bank_transfer",
    settled_at: "2026-09-18T09:00:00Z",
  },
  order_uuid: "order-1",
  credits_purchased: 10,
  credits_remaining_in_lot: 7,
  consumptions: [
    { credits: 1, entry_uuid: "entry-1", occurred_at: "2026-09-19T09:00:00Z" },
    { credits: 2, entry_uuid: "entry-2", occurred_at: "2026-09-20T09:00:00Z" },
  ],
  trail: [
    {
      event: "approved",
      subject_type: "order",
      subject_uuid: "order-1",
      payment_uuid: "pay-1",
      actor_name: "موظف المالية",
      ip_address: "10.0.0.1",
      user_agent: null,
      properties: {},
      occurred_at: "2026-09-18T09:00:00Z",
    },
  ],
  ...overrides,
});

beforeEach(() => {
  vi.clearAllMocks();
});

async function open() {
  await act(async () => {
    render(<PaymentAuditChainPage />);
  });
}

describe("one payment's chain", () => {
  it("asks for the payment in the url and shows what it bought, what is left, and where it went", async () => {
    chain.mockResolvedValue({ data: creditChain() });

    await open();

    expect(chain).toHaveBeenCalledWith("pay-1");
    expect(screen.getByText("مُحصَّلة")).toBeDefined();
    expect(screen.getByText("تحويل بنكي")).toBeDefined();
    expect(screen.getByText("order-1")).toBeDefined();
    // Counted nouns, never a template literal.
    expect(screen.getByText("١٠ حصص")).toBeDefined();
    expect(screen.getByText("٧ حصص")).toBeDefined();
    expect(screen.getByText("entry-1")).toBeDefined();
    expect(screen.getByText("entry-2")).toBeDefined();
    expect(screen.getByText("اعتماد دفعة")).toBeDefined();
    expect(screen.getByText("موظف المالية")).toBeDefined();
  });

  it("prints «—» for a sale that bought no credits, never a zero", async () => {
    chain.mockResolvedValue({
      data: creditChain({ credits_purchased: null, credits_remaining_in_lot: null, consumptions: [] }),
    });

    await open();

    expect(screen.queryByText(/٠/)).toBeNull();
    expect(screen.getByText("لم يُصرف شيء من هذه الدفعة بعد")).toBeDefined();
  });

  it("answers a missing payment in Arabic, and retries on request", async () => {
    chain.mockRejectedValueOnce(new ApiError("Not Found", 404, { message: "No query results" }));

    await open();

    expect(screen.getByText("تعذّر تحميل الدفعة")).toBeDefined();
    expect(screen.getByText("العنصر المطلوب غير موجود أو حُذف.")).toBeDefined();
    expect(screen.queryByText(/No query results/)).toBeNull();

    chain.mockResolvedValue({ data: creditChain() });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /أعد المحاولة|إعادة المحاولة/ }));
    });

    expect(chain).toHaveBeenCalledTimes(2);
    expect(screen.getByText("مُحصَّلة")).toBeDefined();
  });

  it("leads back to the list it came from", async () => {
    chain.mockResolvedValue({ data: creditChain() });

    await open();

    expect(screen.getByRole("link", { name: "العودة إلى السجلّ" }).getAttribute("href")).toBe(
      "/manage/payments/audit",
    );
  });
});
