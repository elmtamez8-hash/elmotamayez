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
