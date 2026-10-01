import { describe, expect, it } from "vitest";
import { htmlToText } from "./html-text";

describe("htmlToText", () => {
  it("keeps the words, drops the markup, and keeps blocks apart", () => {
    expect(htmlToText("<h2>عنوان</h2>\n<p>نص <strong>غامق</strong> &amp; <a href=\"x\">رابط</a></p>")).toBe(
      "عنوان\n\nنص غامق & رابط",
    );
  });

  it("answers empty for nothing", () => {
    expect(htmlToText(null)).toBe("");
    expect(htmlToText("")).toBe("");
  });
});
