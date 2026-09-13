import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { TopicRail } from "./TopicRail";

const categories = [
  { slug: "study-guide", name: "نصائح للطلاب", articles_count: 6 },
  { slug: "exams", name: "امتحانات", articles_count: 2 },
];

const tags = [{ slug: "focus", name: "تركيز", articles_count: 3 }];

describe("TopicRail", () => {
  it("renders nothing at all for a blog with no topics", () => {
    // شريطٌ فيه «الكل» وحدَها اختيارٌ من واحد — إطارٌ وحدود بلا قرارٍ خلفَها.
    const { container } = render(<TopicRail categories={[]} tags={[]} />);

    expect(container.innerHTML).toBe("");
  });

  it("links every chip to the filter the index already understands", () => {
    render(<TopicRail categories={categories} tags={tags} />);

    expect(
      screen.getByRole("link", { name: /نصائح للطلاب/ }).getAttribute("href"),
    ).toBe("/blog?category=study-guide");
    expect(screen.getByRole("link", { name: /تركيز/ }).getAttribute("href")).toBe(
      "/blog?tag=focus",
    );
    expect(screen.getByRole("link", { name: "الكل" }).getAttribute("href")).toBe("/blog");
  });

  it("marks the open door with aria-current, not colour alone", () => {
    /*
      ⚠️ التأكيدُ على `aria-current` لا على صنفِ CSS: الشريحةُ النشطةُ مميَّزةٌ
      بالخلفيّةِ للعين، وقارئُ الشاشةِ لا يرى خلفيّة — فاختبارٌ يقيسُ الصنفَ
      يمرُّ فوقَ بناءٍ لا يقولُ لأحدٍ أيُّ بابٍ مفتوح.
    */
    render(<TopicRail categories={categories} tags={tags} activeCategory="exams" />);

    expect(
      screen.getByRole("link", { name: /امتحانات/ }).getAttribute("aria-current"),
    ).toBe("page");
    expect(screen.getByRole("link", { name: "الكل" }).getAttribute("aria-current")).toBeNull();
    expect(
      screen.getByRole("link", { name: /نصائح للطلاب/ }).getAttribute("aria-current"),
    ).toBeNull();
  });

  it("marks «الكل» when nothing is filtered, so one door is always open", () => {
    render(<TopicRail categories={categories} tags={tags} />);

    expect(screen.getByRole("link", { name: "الكل" }).getAttribute("aria-current")).toBe(
      "page",
    );
  });

  it("counts in Arabic-Indic digits, like every other number in the product", () => {
    // «6» بجانبِ نصٍّ عربيٍّ هو نظامُ أرقامٍ ثانٍ على صفحةٍ واحدة — العطبُ الذي
    // وُجِدَ `lib/numerals` من أجلِه.
    render(<TopicRail categories={categories} tags={tags} />);

    expect(screen.getByRole("link", { name: /نصائح للطلاب/ }).textContent).toContain("٦");
  });

  it("keeps the tag row out entirely when there are no tags", () => {
    render(<TopicRail categories={categories} tags={[]} />);

    expect(screen.queryByText("وسوم")).toBeNull();
  });
});
