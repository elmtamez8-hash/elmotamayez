import { describe, expect, it } from "vitest";

import { demoteHeadings } from "./demote-headings";

describe("demoteHeadings", () => {
  it("moves every heading one level down, opening and closing tags alike", () => {
    expect(demoteHeadings('<h1>سياسة الخصوصية</h1><h2 id="a">ما نجمعه</h2><h3>تفصيل</h3>')).toBe(
      '<h2>سياسة الخصوصية</h2><h3 id="a">ما نجمعه</h3><h4>تفصيل</h4>',
    );
  });

  it("leaves no h1 behind and never invents an h7", () => {
    const out = demoteHeadings("<h1>أ</h1><h6>ب</h6>");

    expect(out).not.toMatch(/<h1[\s>]/);
    expect(out).toBe("<h2>أ</h2><h6>ب</h6>");
  });

  it("touches nothing that only looks like a heading", () => {
    expect(demoteHeadings("<p>h1 وh2</p><hr><header>x</header>")).toBe("<p>h1 وh2</p><hr><header>x</header>");
  });
});
