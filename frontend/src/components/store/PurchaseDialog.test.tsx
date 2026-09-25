import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PurchaseDialog } from "./PurchaseDialog";
import type { StoreItem } from "@/lib/store";

/*
| A discount code typed and never applied is not silently dropped.
|
| Only an APPLIED code reached the purchase request, so a buyer who typed one and
| pressed «تأكيد الطلب» paid full price with their code still showing in the box.
*/

const buy = vi.fn();
const previewDiscount = vi.fn();

vi.mock("@/lib/store", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/store")>()),
  store: {
    buy: (...args: unknown[]) => buy(...args),
    previewDiscount: (...args: unknown[]) => previewDiscount(...args),
    newIdempotencyKey: () => "key-1",
  },
}));

const ITEM: StoreItem = {
  uuid: "item-1",
  kind: "digital",
  kind_label: "ملف رقمي",
  title: "ملخص الفيزياء",
  excerpt: null,
  description: null,
  price_minor: 5000,
  currency: "QAR",
  shipping_fee_minor: null,
  stock: null,
  is_active: true,
  commission_bps: 1000,
  created_at: null,
} as StoreItem;

beforeEach(() => {
  vi.clearAllMocks();
  // The automatic (family) preview on open finds nothing.
  previewDiscount.mockResolvedValue({ discount_minor: 0, source: null, label: null });
  // The bare purchase: `StoreOrderResource` goes out unwrapped (`withoutWrapping`).
  buy.mockResolvedValue({ uuid: "p-1" });
});

describe("buying with a discount code", () => {
  it("refuses to send the order while a typed code has not been applied", async () => {
    await act(async () => {
      render(<PurchaseDialog item={ITEM} onDone={vi.fn()} onCancel={vi.fn()} />);
    });

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "SUMMER26" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تأكيد الطلب" }));
    });

    expect(buy).not.toHaveBeenCalled();
    expect(screen.getByText("اضغط «تطبيق» على كود الخصم، أو امسحه، قبل تأكيد الطلب.")).toBeDefined();
  });

  it("sends the code once it has been applied", async () => {
    const onDone = vi.fn();

    await act(async () => {
      render(<PurchaseDialog item={ITEM} onDone={onDone} onCancel={vi.fn()} />);
    });

    previewDiscount.mockResolvedValue({ discount_minor: 500, source: "coupon", label: "كوبون" });
    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "SUMMER26" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تطبيق" }));
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تأكيد الطلب" }));
    });

    expect(buy).toHaveBeenCalledWith(
      expect.objectContaining({ item_uuid: "item-1", coupon_code: "SUMMER26" }),
      "key-1",
    );
    // The purchase itself, never `res.data` — which is undefined on this wire.
    expect(onDone).toHaveBeenCalledWith({ uuid: "p-1" });
  });
});
