import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
| EVERY TAB IN THE APP READ «لوحة التحكم» (end-to-end run, 2026-09-26).
|
| These pages are client components, and a `"use client"` page cannot export
| `metadata` — so without a `layout.tsx` beside it the tab falls through to the
| `(app)` group's default. A reader with six of them open could not tell their
| orders from their lessons. This walks the main routes and asks each one for its
| own title; a new route with the same shape belongs in the list.
*/

const SHELL = join(process.cwd(), "src", "app", "(app)", "(shell)");

const ROUTES = [
  "enrollments",
  "learn",
  "orders",
  "subscribe",
  "plans",
  "schedule",
  "settings",
  "family",
  "notifications",
  "manage/courses",
  "manage/sessions",
  "manage/cohorts",
  "manage/assignments",
  "manage/payments/reconciliation",
  "manage/payments/audit",
  "manage/payments/collection",
];

describe("app route titles", () => {
  it.each(ROUTES)("%s names its own tab", (route) => {
    const layout = join(SHELL, route, "layout.tsx");

    expect(existsSync(layout)).toBe(true);

    const title = /title:\s*"([^"]+)"/.exec(readFileSync(layout, "utf8"))?.[1];

    expect(title).toBeDefined();
    expect(title).not.toBe("لوحة التحكم");
  });
});
