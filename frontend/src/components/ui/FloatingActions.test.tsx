import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { FloatingActions, hidesFloatingChrome } from "./FloatingActions";
import { STICKY_CTA_HEIGHT_VAR, StickyCtaBar } from "./StickyCtaBar";
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
  it.each([
    "/sessions/abc-123/room",
    "/exams/abc-123/take",
    // الشاتُ ملءَ الشاشة: زرُّ الواتسابِ كانَ فوقَ «إرسال» و«إزالة» (بلاغُ
    // ٢٠٢٦-٠٩-٢٨)، والقائمةُ على الهاتفِ هي اللوحةُ نفسُها بارتفاعِها الكامل.
    "/messages",
    "/messages/abc-123",
    // السبّورةُ هي التبويبُ الذي يُشارَكُ مع الصفّ: زرٌّ عليها يُرسَمُ في فيديو كلِّ طالب.
    "/whiteboard/abc-123",
  ])(
    "draws nothing on %s",
    (path) => {
      pathname.mockReturnValue(path);
      mount("97455512345");

      expect(screen.queryByRole("link", { name: "تواصل معنا عبر واتساب" })).toBeNull();
      expect(screen.queryByRole("button", { name: "العودة إلى أعلى الصفحة" })).toBeNull();
    },
  );

  it.each(["/messagesX", "/settings/messages-sound", "/orders"])(
    "still draws on %s, which only looks like the chat",
    (path) => {
      expect(hidesFloatingChrome(path)).toBe(false);
    },
  );
});

/*
| ⚠️ The button stands on the height a sticky bottom bar publishes, not on a
| guessed `bottom-24`: on a phone it sat a few pixels from «احجز الآن». A typo in
| the variable name would fall back to 0 in silence, so the name is asserted.
*/
describe("its distance from a sticky bottom bar", () => {
  it("stands above the height the bar publishes", () => {
    mount("97455512345");

    const stack = screen.getByRole("link", { name: "تواصل معنا عبر واتساب" }).parentElement;

    expect(stack?.className).toContain(`bottom-[calc(var(${STICKY_CTA_HEIGHT_VAR},0px)+1.5rem)]`);
    expect(stack?.className).not.toContain("bottom-24");
  });

  it("the bar publishes its height and takes it back when it leaves", () => {
    const { unmount } = render(<StickyCtaBar className="fixed">احجز الآن</StickyCtaBar>);

    expect(document.documentElement.style.getPropertyValue(STICKY_CTA_HEIGHT_VAR)).toBe("0px");

    unmount();

    expect(document.documentElement.style.getPropertyValue(STICKY_CTA_HEIGHT_VAR)).toBe("");
  });
});
