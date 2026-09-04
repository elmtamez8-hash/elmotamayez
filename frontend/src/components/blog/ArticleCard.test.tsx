import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ArticleCard } from "./ArticleCard";
import type { ArticleCard as Article } from "@/lib/public-api";

const base: Article = {
  uuid: "a-1",
  slug: "خطة-المراجعة",
  title: "خطّة المراجعة النهائيّة",
  excerpt: "ابدأْ قبل أسبوعين.",
  cover_url: null,
  published_at: "2026-09-01T10:00:00Z",
  updated_at: "2026-09-01T10:00:00Z",
  category: { slug: "study-tips", name: "نصائح دراسيّة" },
  tags: [],
};

describe("ArticleCard", () => {
  it("shows the cover when there is one", () => {
    render(<ArticleCard article={{ ...base, cover_url: "/storage/a.webp" }} />);

    /*
    | ⚠️ الصورةُ زخرفةٌ بجانبِ عنوانٍ يقولُ ما فيها، فهي `aria-hidden` وبلا اسمٍ
    | مسموع — ولذلك تُقرَأُ من DOM لا من دورٍ يُستعلَمُ عنه. اختبارٌ يبحثُ عن
    | `getByRole("img")` هنا يفشلُ عن تصميمٍ صحيح.
    */
    const image = document.querySelector("img");

    expect(image?.getAttribute("src")).toBe("/storage/a.webp");
    expect(image?.getAttribute("alt")).toBe("");
  });

  it("renders no image element at all when the article has no cover", () => {
    /*
    | ⚠️ أغلبُ المقالاتِ بلا غلاف. بطاقةٌ تُصيِّرُ `<img>` بمصدرٍ فارغٍ تعرضُ
    | أيقونةَ صورةٍ مكسورةٍ اثنتَي عشرةَ مرّةً في الفهرس — وهي تُقرَأُ عطلاً في
    | الموقعِ لا مقالاً بلا صورة.
    */
    render(<ArticleCard article={base} />);

    expect(document.querySelector("img")).toBeNull();
  });

  it("keeps the category a link of its own beside the article link", () => {
    /*
    | ⚠️ رابطانِ لا رابط. الشارةُ تُرشِّحُ المدوّنةَ بالتصنيف، والعنوانُ يفتحُ
    | المقال — ولو صارَ التصنيفُ نصّاً لصارَ المرشِّحُ المبنيُّ في الخلفيّةِ بلا
    | بابٍ يصلُه.
    |
    | ⚠️ وحدُّ ما يقيسُه هذا الاختبار: الوجودُ والوجهة. أنّ نقرةَ التصنيفِ لا
    | يبتلعُها طبقُ `::after` الذي يغطّي البطاقةَ قرارُ **تكديسٍ في CSS**
    | (`relative z-10`)، وjsdom لا يحسبُ تكديساً ولا يُصيِّرُ عنصراً زائفاً. مكتوبٌ
    | هنا لأنّ الفرقَ بينَ «الرابطُ موجود» و«الرابطُ يعمل» هو بالضبطِ العطلُ الذي
    | شُحِنَ ثمّ أُصلِح.
    */
    render(<ArticleCard article={base} />);

    expect(
      screen.getByRole("link", { name: "نصائح دراسيّة" }).getAttribute("href"),
    ).toBe("/blog?category=study-tips");

    expect(
      screen.getByRole("link", { name: base.title }).getAttribute("href"),
    ).toBe(`/blog/${base.slug}`);
  });

  it("does not pre-encode the Arabic slug", () => {
    /*
    | ⚠️ Next يُرمِّزُ `href` بنفسِه، وترميزٌ مسبقٌ يحوّلُ `%D8%AE` إلى `%25D8%AE`
    | — وكلُّ رابطٍ عربيٍّ ‏٤٠٤. القاعدةُ كانت مكتوبةً في الصفحةِ الأصليّةِ
    | وانتقلَت مع الشيفرةِ إلى هنا، فالاختبارُ ينتقلُ معها.
    */
    render(<ArticleCard article={base} />);

    const href = screen
      .getByRole("link", { name: base.title })
      .getAttribute("href");

    expect(href).not.toContain("%25");
    expect(href).toContain("خطة-المراجعة");
  });
});
