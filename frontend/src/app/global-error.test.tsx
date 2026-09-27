import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it, vi } from "vitest";

import GlobalError from "./global-error";

// The stylesheet is Tailwind's PostCSS pipeline, which vitest does not run.
vi.mock("./globals.css", () => ({}));

/*
| The root layout's stand-in. It must declare the Arabic RTL document itself (the
| layout that does so is the thing that failed), and it must never print the
| thrown message — only the digest a support request can quote.
*/
describe("global-error", () => {
  const error = Object.assign(new Error("TypeError: cannot read 'name' of undefined"), {
    digest: "4021337",
  });

  const html = renderToStaticMarkup(<GlobalError error={error} />);

  it("renders its own Arabic, right-to-left document", () => {
    expect(html).toMatch(/<html lang="ar" dir="rtl">/);
    expect(html).toContain("<body");
  });

  it("says what happened in Arabic and offers a reload", () => {
    expect(html).toContain("تعذّر تحميل الموقع");
    expect(html).toContain("إعادة تحميل الصفحة");
  });

  it("shows the digest and never the raw message", () => {
    expect(html).toContain("4021337");
    expect(html).not.toContain("cannot read");
  });
});
