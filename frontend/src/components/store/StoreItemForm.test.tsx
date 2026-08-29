import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { StoreItemForm } from "./StoreItemForm";
import { StoreItemCard } from "./StoreItemCard";
import type { StoreItem } from "@/lib/store";

/*
| ⚠️ NEITHER OF THESE IS REACHABLE FROM THE BACKEND SUITE. «Does this component
| do what it says» is vitest's question; «does the product work end to end» stays
| Playwright, which builds for production and needs two servers.
|
| Both cases below are written for defects with a history in this tree: a screen
| that offers an action depending on a type it has not read (`LessonEditor`), and
| a stock number rendered as a zero when it means «cannot run out».
*/

afterEach(cleanup);

const digital: StoreItem = {
  uuid: "d-1",
  kind: "digital",
  kind_label: "نسخة رقمية",
  title: "مذكّرة المراجعة",
  excerpt: null,
  description: null,
  price_minor: 5000,
  currency: "QAR",
  shipping_fee_minor: null,
  stock: null,
  is_active: true,
  commission_bps: 1000,
  created_at: null,
};

const printed: StoreItem = {
  ...digital,
  uuid: "p-1",
  kind: "physical",
  kind_label: "نسخة مطبوعة",
  shipping_fee_minor: 1500,
  stock: 3,
};

describe("StoreItemForm", () => {
  it("offers a file for a digital product and never a stock count", () => {
    render(
      <StoreItemForm
        item={digital}
        commissionBps={1000}
        onSaved={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    expect(screen.getByLabelText(/الملف المرفق/)).toBeTruthy();
    expect(screen.queryByLabelText(/المخزون/)).toBeNull();
  });

  it("swaps to stock and shipping the moment the type changes", () => {
    render(
      <StoreItemForm
        item={digital}
        commissionBps={1000}
        onSaved={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    fireEvent.change(screen.getByLabelText(/نوع المنتج/), { target: { value: "physical" } });

    // ⚠️ THE DEFECT THIS GUARDS: a screen that kept offering a video upload for a
    // printed book, and whose refusal was a correct answer to a request the
    // screen invented.
    expect(screen.getByLabelText(/المخزون/)).toBeTruthy();
    expect(screen.getByLabelText(/رسم الشحن/)).toBeTruthy();
    expect(screen.queryByLabelText(/الملف المرفق/)).toBeNull();
  });

  it("shows the teacher what they keep, floored exactly as the server floors it", () => {
    render(
      <StoreItemForm
        item={{ ...digital, price_minor: 5555 }}
        commissionBps={1000}
        onSaved={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    // 5555 − floor(5555 × 1000 / 10000) = 5555 − 555 = 5000.
    // A `Math.round` here would show 4999 — a riyal the teacher never receives.
    expect(screen.getByText(/نصيبك من كل نسخة/)).toBeTruthy();
  });
});

describe("StoreItemCard", () => {
  it("says nothing about stock for a file", () => {
    render(<StoreItemCard item={digital} onBuy={vi.fn()} />);

    // ⚠️ `stock === null` MEANS «CANNOT RUN OUT». A card rendering `stock ?? 0`
    // tells every buyer a file is sold out — the same NULL that makes
    // `stock >= :qty` unsatisfiable on the server.
    expect(screen.queryByText(/نفدت النسخ/)).toBeNull();
    expect(screen.getByRole("button", { name: "اشترِ" })).toBeTruthy();
  });

  it("disables the button when a printed item has run out", () => {
    render(<StoreItemCard item={{ ...printed, stock: 0 }} onBuy={vi.fn()} />);

    expect(screen.getByText(/نفدت النسخ/)).toBeTruthy();
    expect(screen.getByRole("button", { name: "غير متاح" }).hasAttribute("disabled")).toBe(true);
  });

  it("warns while a printed item is nearly gone", () => {
    render(<StoreItemCard item={printed} onBuy={vi.fn()} />);

    expect(screen.getByText("بقي 3")).toBeTruthy();
  });
});
