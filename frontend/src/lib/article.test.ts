import { describe, expect, it } from "vitest";
import {
  headingSlug,
  isoMinutes,
  readingMinutes,
  withHeadingAnchors,
  wordCount,
} from "./article";

describe("readingMinutes", () => {
  it("never answers zero", () => {
    // ⚠️ «‏٠ دقيقة» تُقرَأُ عطلاً في العرضِ لا وصفاً لمقالٍ قصير.
    expect(readingMinutes("<p>كلمتان فقط</p>")).toBe(1);
  });

  it("counts the words and not the markup", () => {
    // مئةٌ وثمانونَ كلمةً = دقيقةٌ واحدة؛ ثلاثُمئةٍ وستّون = دقيقتان.
    const words = Array.from({ length: 360 }, () => "كلمة").join(" ");

    expect(readingMinutes(`<p>${words}</p>`)).toBe(2);

    // ⚠️ والوسمُ لا يُعَدّ: نصٌّ واحدٌ ملفوفٌ في عشرةِ وسومٍ ليس أطولَ منه عارياً.
    const wrapped = `<div><section><p><strong>${words}</strong></p></section></div>`;

    expect(readingMinutes(wrapped)).toBe(2);
  });

  it("does not count an html entity as a word", () => {
    expect(wordCount("<p>كلمة&nbsp;&amp;&nbsp;كلمة</p>")).toBe(2);
  });
});

describe("headingSlug", () => {
  it("keeps the Arabic and never encodes it", () => {
    /*
    | ⚠️ لا ترميزَ هنا: المتصفّحُ يُرمِّزُ ما بعدَ `#` بنفسِه، وترميزٌ مسبقٌ
    | يُنتِجُ ترميزاً مزدوجاً — العطلُ نفسُه الذي تحرسُ منه صفحةُ المقالِ في
    | رابطِها.
    */
    // ⚠️ والشدّةُ تسقطُ مع بقيّةِ التشكيل — انظرِ الحالةَ التالية.
    expect(headingSlug("خطّة المراجعة")).toBe("خطة-المراجعة");
    expect(headingSlug("خطّة المراجعة")).not.toContain("%");
  });

  it("reads a vowelled heading and a bare one as one anchor", () => {
    // ⚠️ وإلّا انكسرَ كلُّ رابطٍ عندَ أوّلِ تحريرٍ يُضيفُ تشكيلاً أو يحذفُه.
    expect(headingSlug("المُراجَعة")).toBe(headingSlug("المراجعة"));
  });

  it("falls back rather than producing an empty anchor", () => {
    expect(headingSlug("!!!")).toBe("قسم");
  });
});

describe("withHeadingAnchors", () => {
  it("injects an id and reports the same one in the table of contents", () => {
    const { html, headings } = withHeadingAnchors(
      "<h2>البداية</h2><p>نصّ</p><h3>تفصيل</h3>",
    );

    expect(headings).toEqual([
      { id: "البداية", text: "البداية", level: 2 },
      { id: "تفصيل", text: "تفصيل", level: 3 },
    ]);

    /*
    | ⚠️ التوكيدُ على الاثنَينِ معاً هو المقصود. فهرسٌ يُبنى في مكانٍ ومِرساةٌ
    | تُحقَنُ في آخرَ هجاءانِ لخوارزميّةٍ واحدةٍ يفترقانِ بصمتٍ عندَ أوّلِ عنوانٍ
    | مكرَّر.
    */
    for (const heading of headings) {
      expect(html).toContain(`id="${heading.id}"`);
    }
  });

  it("gives a repeated heading an anchor of its own", () => {
    // «تمارين» مرّتَينِ شائعٌ في مقالٍ تعليميّ، والمِرساةُ الواحدةُ تجعلُ
    // الرابطَينِ يقفزانِ إلى الموضعِ الأوّلِ ويُقرَأُ الفهرسُ معطّلاً.
    const { html, headings } = withHeadingAnchors(
      "<h2>تمارين</h2><h2>تمارين</h2>",
    );

    expect(headings.map((heading) => heading.id)).toEqual([
      "تمارين",
      "تمارين-2",
    ]);
    expect(html).toContain('id="تمارين-2"');
  });

  it("leaves an id the author already wrote", () => {
    const { html, headings } = withHeadingAnchors('<h2 id="mine">عنوان</h2>');

    expect(headings[0]?.id).toBe("mine");
    // ⚠️ ولا يُضافُ ثانٍ بجانبِه: وسمٌ بمعرِّفَينِ وسمٌ لا يُحلَّلُ كما يُتوقَّع.
    expect(html).toBe('<h2 id="mine">عنوان</h2>');
  });

  it("ignores h1 and leaves body text untouched", () => {
    const source = "<h1>العنوان</h1><p>فقرة</p>";
    const { html, headings } = withHeadingAnchors(source);

    // العنوانُ الأوّلُ عنوانُ الصفحةِ، وفهرسٌ يبدأُ به يُفهرِسُ نفسَه.
    expect(headings).toEqual([]);
    expect(html).toBe(source);
  });
});

describe("isoMinutes", () => {
  it("is a duration, not a sentence", () => {
    // محرّكاتُ الإجابةِ تقرأُ `timeRequired` حرفيّاً، و«‏٥ دقائق» نصٌّ لا مدّة.
    expect(isoMinutes(5)).toBe("PT5M");
  });
});
