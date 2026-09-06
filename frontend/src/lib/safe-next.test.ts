import { describe, expect, it } from "vitest";

import { safeNext } from "./safe-next";

/*
 * Spec 027 · FR-005. Only a component test can see any of this: the backend does
 * not know the parameter, and Playwright would have to be phished to notice.
 */
describe("safeNext", () => {
  it("keeps a same-origin path with its query", () => {
    expect(safeNext("/subscribe?course=abc&cohort=def", "/dashboard")).toBe(
      "/subscribe?course=abc&cohort=def",
    );
  });

  it("falls back when there is nothing to go back to", () => {
    expect(safeNext(null, "/dashboard")).toBe("/dashboard");
    expect(safeNext(undefined, "/dashboard")).toBe("/dashboard");
    expect(safeNext("", "/dashboard")).toBe("/dashboard");
  });

  it("refuses a protocol-relative URL", () => {
    expect(safeNext("//evil.example/login", "/dashboard")).toBe("/dashboard");
  });

  it("refuses the BACKSLASH form, which the obvious guard lets through", () => {
    /*
     * The reason this file exists. A guard written as «starts with / and not
     * with //» accepts both of these, and a browser normalises the backslash
     * while parsing — so the victim signs in on the real login page and is
     * deposited on an attacker's.
     */
    expect(safeNext("/\\evil.example", "/dashboard")).toBe("/dashboard");
    expect(safeNext("/\\/evil.example", "/dashboard")).toBe("/dashboard");
  });

  it("refuses an absolute URL, however friendly it looks", () => {
    expect(safeNext("https://evil.example/subscribe", "/dashboard")).toBe("/dashboard");
    expect(safeNext("http://localhost:3000/subscribe", "/dashboard")).toBe("/dashboard");
  });

  it("refuses anything that is not a path", () => {
    expect(safeNext("javascript:alert(1)", "/dashboard")).toBe("/dashboard");
    expect(safeNext("subscribe", "/dashboard")).toBe("/dashboard");
  });
});
