import { afterEach, describe, expect, it, vi } from "vitest";

import { platformIdentity } from "./platform";

function answer(data: Record<string, unknown>) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => new Response(JSON.stringify({ data }), { status: 200 })),
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("platformIdentity — the freeze limits /terms states", () => {
  it("reads both limits as whole numbers", async () => {
    answer({ name: "المتميز", freeze_max_days: 14, freeze_max_per_month: 3 });

    const identity = await platformIdentity();

    expect(identity.freezeMaxDays).toBe(14);
    expect(identity.freezeMaxPerMonth).toBe(3);
  });

  it("reads an older API, a string or a zero as «unknown», never as a guess", async () => {
    answer({ name: "المتميز", freeze_max_days: "30", freeze_max_per_month: 0 });

    const identity = await platformIdentity();

    expect(identity.freezeMaxDays).toBeNull();
    expect(identity.freezeMaxPerMonth).toBeNull();
  });

  it("falls back to «unknown» when the API cannot answer", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new Error("down");
      }),
    );

    const identity = await platformIdentity();

    expect(identity.freezeMaxDays).toBeNull();
    expect(identity.freezeMaxPerMonth).toBeNull();
  });
});

describe("platformIdentity — the other numbers /terms and /refunds state", () => {
  it("reads each one by its wire name, zero included", async () => {
    answer({
      name: "المتميز",
      max_unredeemed_credits: 24,
      cancellation_window_minutes: 1440,
      attendance_required_stay_percent: 50,
      deferred_initial_credits: 0,
      store_refund_window_hours: 0,
    });

    const { terms } = await platformIdentity();

    expect(terms.maxUnredeemedCredits).toBe(24);
    expect(terms.cancellationWindowMinutes).toBe(1440);
    expect(terms.attendanceRequiredStayPercent).toBe(50);
    // A real setting, not «unknown» — the page words a zero.
    expect(terms.deferredInitialCredits).toBe(0);
    expect(terms.storeRefundWindowHours).toBe(0);
  });

  it("reads a missing field, a string, a fraction or a negative as «unknown»", async () => {
    answer({
      name: "المتميز",
      max_unredeemed_credits: "24",
      review_period_days: 1.5,
      renewal_notice_days: -1,
    });

    const { terms } = await platformIdentity();

    expect(terms.maxUnredeemedCredits).toBeNull();
    expect(terms.reviewPeriodDays).toBeNull();
    expect(terms.renewalNoticeDays).toBeNull();
    expect(terms.twoFactorGraceDays).toBeNull();
  });

  it("knows none of them when the API cannot answer", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new Error("down");
      }),
    );

    const { terms } = await platformIdentity();

    expect(Object.values(terms).every((value) => value === null)).toBe(true);
  });
});
