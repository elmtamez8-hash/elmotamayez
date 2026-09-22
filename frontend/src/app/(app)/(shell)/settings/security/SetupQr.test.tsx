import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { SetupQr } from "./SetupQr";

/*
| ٢٠٢٦-٠٩-٢٢ — «ظاهرلي كود وليس qrcode». بُلِّغَ أثناءَ مشيِ ٠٢٧ · T074.
|
| ⛔ شاشةُ الأمانِ كانت تعرضُ مفتاحاً من ٣٢ حرفاً ورابطَ `otpauth://` — والرابطُ
| لا يفتحُ شيئاً على حاسوب، فالطريقُ الوحيدُ كانَ قراءةَ سرٍّ من شاشةٍ وطباعتَه
| في هاتف.
|
| ⚠️ **والحارسُ يعدُّ المربّعاتِ المرسومةَ، لا يسألُ عن وجودِ `<svg>`**: مولِّدٌ
| يفشلُ يردُّ `null` وعنصرٌ فارغٌ يبقى `<svg>` سليماً في الشجرة — فاختبارٌ يسألُ
| عن العنصرِ وحدَه يمرُّ فوقَ مربّعٍ أبيضَ لا يقرؤُه ماسحٌ أبداً. وهي عائلةُ
| «صنفُ لونٍ لرمزٍ غيرِ معرَّفٍ لا يرسمُ شيئاً بصمت» المسجَّلةُ في `CLAUDE.md`.
*/
describe("SetupQr", () => {
  const uri =
    "otpauth://totp/%D8%A7%D9%84%D9%85%D8%AA%D9%85%D9%8A%D8%B2:teacher@example.com" +
    "?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=%D8%A7%D9%84%D9%85%D8%AA%D9%85%D9%8A%D8%B2";

  it("draws the code, not an empty square", () => {
    const { container } = render(<SetupQr uri={uri} />);

    const rects = container.querySelectorAll("rect");

    // واحدٌ للخلفيّةِ البيضاء، والباقي وحداتٌ سوداء. الرقمُ متحفِّظٌ عمداً: أصغرُ
    // نسخةٍ تسعُ هذا الرابطَ تتجاوزُه بكثير، والمقصودُ أن يفشلَ عندَ الصفرِ لا أن
    // يُثبَّتَ عددٌ يتغيّرُ مع أيِّ تعديلٍ في النصّ.
    expect(rects.length).toBeGreaterThan(50);
  });

  it("keeps the quiet zone, which a scanner needs to find the code at all", () => {
    const { container } = render(<SetupQr uri={uri} />);

    // الهامشُ وحدتان على كلِّ جانب: `viewBox` يبدأُ من ‎-2 والمساحةُ = العدّ + ٤.
    const box = container
      .querySelector("svg")
      ?.getAttribute("viewBox")
      ?.split(" ");

    expect(box?.[0]).toBe("-2");
    expect(box?.[1]).toBe("-2");
    expect(Number(box?.[2])).toBe(Number(box?.[3]));
  });
});
