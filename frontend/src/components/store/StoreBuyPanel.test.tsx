import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { PublicStoreItem } from "@/lib/public-api";

const auth = vi.hoisted(() => ({ user: null as null | { uuid: string }, loading: false }));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => auth }));

const { StoreBuyPanel } = await import("./StoreBuyPanel");

afterEach(cleanup);

const ITEM: PublicStoreItem = {
  uuid: "p-1",
  kind: "digital",
  kind_label: "نسخة رقمية",
  title: "ملخّص",
  excerpt: null,
  price_minor: 5000,
  currency: "QAR",
  shipping_fee_minor: null,
  is_available: true,
  cover_url: null,
  teacher: null,
  subject: null,
  course: null,
};

describe("StoreBuyPanel", () => {
  it("sends a guest to sign in and back to this product", () => {
    auth.user = null;
    render(<StoreBuyPanel item={ITEM} />);

    expect(screen.getByRole("link", { name: "سجّل الدخول لتشتري" }).getAttribute("href")).toBe(
      `/login?next=${encodeURIComponent("/store/p-1")}`,
    );
  });

  it("offers the buy button to a signed-in reader", () => {
    auth.user = { uuid: "u-1" };
    render(<StoreBuyPanel item={ITEM} />);

    expect(screen.getByRole("button", { name: "اشترِ الآن" })).toBeTruthy();
  });

  it("offers nothing to buy when the product is sold out", () => {
    auth.user = { uuid: "u-1" };
    render(<StoreBuyPanel item={{ ...ITEM, is_available: false }} />);

    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.getByText(/نفدت النسخ/)).toBeTruthy();
  });
});
