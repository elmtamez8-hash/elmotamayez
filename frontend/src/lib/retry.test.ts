import { describe, expect, it } from "vitest";

import { ApiError } from "./api";
import { backoffDelay, isTransientFailure } from "./retry";

describe("isTransientFailure", () => {
  it.each([500, 502, 503, 504, 429])("retries a %i", (status) => {
    expect(isTransientFailure(new ApiError("x", status, null))).toBe(true);
  });

  it.each([400, 401, 403, 404, 409, 410, 422])("never retries a %i", (status) => {
    expect(isTransientFailure(new ApiError("x", status, null))).toBe(false);
  });

  it("retries a request that never reached a server", () => {
    expect(isTransientFailure(new TypeError("Failed to fetch"))).toBe(true);
  });

  it("treats anything it does not recognise as final", () => {
    expect(isTransientFailure(new Error("boom"))).toBe(false);
    expect(isTransientFailure("nope")).toBe(false);
  });
});

describe("backoffDelay", () => {
  it("doubles from two seconds and stops at thirty", () => {
    expect([1, 2, 3, 4, 5, 6, 10].map(backoffDelay)).toEqual([
      2_000, 4_000, 8_000, 16_000, 30_000, 30_000, 30_000,
    ]);
  });
});
