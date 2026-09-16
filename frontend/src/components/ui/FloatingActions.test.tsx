import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { FloatingActions } from "./FloatingActions";
import { PlatformProvider } from "@/lib/platform-context";

/*
| ⛔ **زرُّ الواتسابِ كانَ مبنيّاً منذُ زمنٍ ولم يرَه أحدٌ قطّ.**
|
| رقمُه كانَ يُقرأُ من `NEXT_PUBLIC_WHATSAPP_NUMBER` — يُدمَجُ وقتَ البناءِ ولم
| يُضبَطْ في أيِّ بيئةٍ ولا حتّى في `.env.example` — فكانَ الحارسُ أدناه كاذباً
| دائماً. ولم يفشلْ شيءٌ في أيِّ مكان، لأنّ الرقمَ الفارغَ هو أيضاً كيفَ يُطفِئُ
| المشغِّلُ الزرَّ عن قصد: العطبُ والإعدادُ الصحيحُ لهما نفسُ الشكلِ بالضبط.
|
| وهو الآنَ صفٌّ في `platform_settings` يُقرأُ وقتَ التشغيل. وهذا الملفُّ هو ما
| يمنعُ عودتَه إلى الاختفاءِ صامتاً.
*/

/*
  ⚠️ `usePathname` HAS NO PROVIDER IN jsdom, so the component throws before it
  renders anything. The value matters as well as its presence: the component
  refuses to draw on `/room` and `/take`, so a mock returning one of those would
  make every assertion below pass over a rendered nothing.
*/
const pathname = vi.fn(() => "/orders");

vi.mock("next/navigation", () => ({ usePathname: () => pathname() }));

function mount(supportWhatsapp: string) {
  render(
    <PlatformProvider name="المتميز" supportWhatsapp={supportWhatsapp}>
      <FloatingActions />
    </PlatformProvider>,
  );
}

beforeEach(() => {
  pathname.mockReturnValue("/orders");
});

describe("the floating WhatsApp button", () => {
  it("links to the number the platform settings carry", () => {
    mount("97455512345");

    const link = screen.getByRole("link", { name: "تواصل معنا عبر واتساب" });

    // ⚠️ الرابطُ بنصِّه، لا مجرّدُ وجودِ الزرّ: `wa.me` بلا رقمٍ صفحةُ تسويقٍ
    // عندَ واتساب، فزرٌّ موجودٌ برابطٍ ناقصٍ يمرُّ من توكيدٍ يسألُ عن وجودِه وحدَه.
    expect(link.getAttribute("href")).toBe("https://wa.me/97455512345");
  });

  it("renders nothing at all when no support line is configured", () => {
    /*
    | ⚠️ يُحذَفُ من الشجرةِ ولا يُخفى. زرٌّ مخفيٌّ يبقى محطَّةَ تنقّلٍ بلوحةِ
    | المفاتيحِ تفتحُ محادثةَ غريبٍ برقمٍ مُخترَع — ولا يوجدُ بديلٌ آمنٌ لرقمِ
    | هاتف، ولهذا كانَ الفراغُ هو الإطفاء.
    */
    mount("");

    expect(screen.queryByRole("link", { name: "تواصل معنا عبر واتساب" })).toBeNull();
  });

  it("keeps the back-to-top button whatever the support line says", () => {
    // المكوّنُ واحدٌ لزرَّين، وإطفاءُ أحدِهما يجبُ ألّا يأخذَ الآخرَ معه.
    mount("");

    expect(screen.getByRole("button", { name: "العودة إلى أعلى الصفحة" })).toBeDefined();
  });
});

/*
| ⚠️ **شاشتان لا يجوزُ أن تعلوَهما طبقةٌ عائمة، ولا لأنّها تحجبُ بكسلات.** غرفةُ
| الحصّةِ حيّة: أزرارُ الكتمِ والإخراجِ على حافّتِها السفلى، وطبقةٌ ثانيةٌ فوقَ
| صفٍّ قائمٍ هي عينُ الأذى الذي يوجدُ `ConfirmButton` لتفاديه. وصفحةُ الاختبارِ
| ورقةٌ مؤقَّتة: رابطٌ يخرجُ بالطالبِ من المحاولةِ في منتصفِها.
*/
describe("where it deliberately does not appear", () => {
  it.each(["/sessions/abc-123/room", "/exams/abc-123/take"])(
    "draws nothing on %s",
    (path) => {
      pathname.mockReturnValue(path);
      mount("97455512345");

      expect(screen.queryByRole("link", { name: "تواصل معنا عبر واتساب" })).toBeNull();
      expect(screen.queryByRole("button", { name: "العودة إلى أعلى الصفحة" })).toBeNull();
    },
  );
});
