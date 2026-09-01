import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { SiteHeader, isCurrentPath } from "./SiteHeader";

/*
| WHICH SECTION AM I IN — asked of the header, which is the only thing on the
| page that can answer it.
|
| ⚠️ THE DEFECT THIS GUARDS IS A GREEN-LOOKING ONE-LINER. `pathname.startsWith(href)`
| reads as the obvious implementation and marks «الرئيسية» current on all six
| screens, because every path in the product starts with "/" — six lit links say
| exactly as much as none. Its mirror is an exact match, which goes dark the
| moment a reader opens a teacher's own page, i.e. precisely when «which section
| is this» is hardest to answer from the content.
|
| Nothing in the backend knows this component and Playwright would only ever
| visit the routes somebody remembered to add to a spec.
*/
vi.mock("@/lib/platform-context", () => ({ usePlatformName: () => "المتميز" }));
vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: null }),
  panelPathFor: () => "/dashboard",
}));

let pathname = "/";
vi.mock("next/navigation", () => ({ usePathname: () => pathname }));

describe("isCurrentPath", () => {
  it("matches the home route exactly and never as a prefix", () => {
    expect(isCurrentPath("/", "/")).toBe(true);
    expect(isCurrentPath("/teachers", "/")).toBe(false);
    expect(isCurrentPath("/blog/hello", "/")).toBe(false);
  });

  it("matches a section and everything beneath it", () => {
    expect(isCurrentPath("/teachers", "/teachers")).toBe(true);
    expect(isCurrentPath("/teachers/9f1c-uuid", "/teachers")).toBe(true);
  });

  it("stops at a path boundary", () => {
    // Without the trailing slash `/coursesomething` would light «الكورسات».
    expect(isCurrentPath("/coursesomething", "/courses")).toBe(false);
    expect(isCurrentPath("/teachers-old", "/teachers")).toBe(false);
  });
});

describe("SiteHeader", () => {
  it("marks exactly one link as the current page", () => {
    pathname = "/courses";
    render(<SiteHeader />);

    const current = screen
      .getAllByRole("link")
      .filter((el) => el.getAttribute("aria-current") === "page");

    // The nav is rendered twice — the bar and the phone drawer share one NAV
    // array — but the drawer is closed here, so one row and one only.
    expect(current).toHaveLength(1);
    expect(current[0].textContent).toContain("الكورسات");
    // The colour is the theme-aware brand ink, not `text-primary`: that one is
    // the maroon a white foreground is earned against and it never lightens in
    // the dark theme.
    expect(current[0].className).toContain("text-primary-ink");
    expect(current[0].className).toContain("font-extrabold");
  });

  it("marks the section from a page beneath it", () => {
    pathname = "/teachers/9f1c-uuid";
    render(<SiteHeader />);

    const current = screen
      .getAllByRole("link")
      .filter((el) => el.getAttribute("aria-current") === "page");

    expect(current).toHaveLength(1);
    expect(current[0].textContent).toContain("المدرسون");
  });
});
