import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { CouponField } from "./CouponField";

/**
 * The discount field (spec 011 · T075 · FR-011).
 *
 * ⚠️ THE PREVIEW MUST NOT FIRE ON KEYSTROKE, and that is the case worth having.
 * `/billing/coupons/preview` carries the tightest rate limiter in the product —
 * ten a minute — because it is the one endpoint whose purpose is to be guessed
 * at. A per-keystroke lookup locks an honest buyer out on the seventh character
 * of their own code, and no backend test can see it: the server would answer
 * every one of those calls correctly.
 *
 * ⚠️ AND THE REFUSAL IS SHOWN AS THE SERVER WORDED IT. The API deliberately does
 * not distinguish «no such code» from «not for this», so a component that
 * composed its own friendlier sentence would be undoing that on the screen.
 */
vi.mock("@/lib/store", () => ({
  store: { previewDiscount: vi.fn() },
}));

vi.mock("@/lib/errors", () => ({
  userMessage: (error: unknown) => (error as { message: string }).message,
}));

vi.mock("@/lib/labels", () => ({
  formatMinorMoney: (minor: number) => `${minor / 100} ر.ق`,
}));

const { store } = await import("@/lib/store");
const preview = store.previewDiscount as unknown as ReturnType<typeof vi.fn>;

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function setup(onApplied = vi.fn()) {
  render(
    <CouponField kind="store_item" uuid="item-uuid" currency="QAR" onApplied={onApplied} />,
  );

  return onApplied;
}

describe("CouponField", () => {
  it("asks the server nothing while the buyer is typing", async () => {
    setup();

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "SUMMER26" } });

    expect(preview).not.toHaveBeenCalled();
  });

  it("previews once when the buyer presses apply", async () => {
    const onApplied = setup();

    preview.mockResolvedValue({ discount_minor: 1000, source: "coupon", label: "كوبون SUMMER26" });

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "summer26" } });
    fireEvent.click(screen.getByRole("button", { name: "تطبيق" }));

    await waitFor(() => expect(onApplied).toHaveBeenCalled());

    expect(preview).toHaveBeenCalledTimes(1);
    // Trimmed, and the CODE travels back beside the discount — the payload
    // deliberately does not carry it.
    expect(onApplied).toHaveBeenCalledWith(expect.objectContaining({ source: "coupon" }), "summer26");
  });

  it("shows the amount that came off", async () => {
    setup();

    preview.mockResolvedValue({ discount_minor: 1500, source: "coupon", label: "كوبون X" });

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "X" } });
    fireEvent.click(screen.getByRole("button", { name: "تطبيق" }));

    expect(await screen.findByText(/كوبون X/)).toBeDefined();
    expect(screen.getByText(/15 ر\.ق/)).toBeDefined();
  });

  it("shows the server refusal verbatim and applies nothing", async () => {
    const onApplied = setup();

    preview.mockRejectedValue({ message: "هذا الكود غير صالح لهذه العملية." });

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "NOPE" } });
    fireEvent.click(screen.getByRole("button", { name: "تطبيق" }));

    expect(await screen.findByText("هذا الكود غير صالح لهذه العملية.")).toBeDefined();
    expect(onApplied).toHaveBeenCalledWith(null, null);
  });

  it("reports a family discount with no code beside it", async () => {
    const onApplied = setup();

    // A family discount has no code by definition, so the purchase request must
    // not carry one — sending the typed string there would spend a coupon the
    // resolver did not choose.
    preview.mockResolvedValue({ discount_minor: 900, source: "sibling", label: "خصم الإخوة ١٠٪" });

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "ANY" } });
    fireEvent.click(screen.getByRole("button", { name: "تطبيق" }));

    await waitFor(() => expect(onApplied).toHaveBeenCalled());

    expect(onApplied).toHaveBeenCalledWith(expect.objectContaining({ source: "sibling" }), null);
  });

  it("clears an applied discount back to nothing", async () => {
    const onApplied = setup();

    preview.mockResolvedValue({ discount_minor: 1000, source: "coupon", label: "كوبون X" });

    fireEvent.change(screen.getByLabelText(/كود خصم/), { target: { value: "X" } });
    fireEvent.click(screen.getByRole("button", { name: "تطبيق" }));

    const remove = await screen.findByRole("button", { name: "إزالة" });
    fireEvent.click(remove);

    expect(onApplied).toHaveBeenLastCalledWith(null, null);
  });

  it("never submits the surrounding form", () => {
    // ⚠️ THE APPLY CONTROL SITS INSIDE THE PURCHASE FORM. A default-typed button
    // there submits the ORDER on Enter — buying the item at full price instead of
    // checking the code.
    setup();

    expect(screen.getByRole("button", { name: "تطبيق" }).getAttribute("type")).toBe("button");
  });
});
