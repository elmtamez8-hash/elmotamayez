import { describe, expect, it } from "vitest";

import { isLiveSubscription } from "./ChildSubscriptionsCard";

describe("isLiveSubscription", () => {
  it("drops an active row whose last day is behind us — the nightly job may not have run", () => {
    expect(isLiveSubscription({ status: "active", ends_on: "2026-10-05" }, "2026-10-29")).toBe(false);
  });

  it("keeps the row through its last day, which is inclusive", () => {
    expect(isLiveSubscription({ status: "active", ends_on: "2026-10-29" }, "2026-10-29")).toBe(true);
    expect(isLiveSubscription({ status: "active", ends_on: "2026-11-04" }, "2026-10-29")).toBe(true);
  });

  it("drops a row that is not active whatever its date", () => {
    expect(isLiveSubscription({ status: "cancelled", ends_on: "2026-11-04" }, "2026-10-29")).toBe(false);
  });
});
