import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import LoginPage from "./page";

/*
| «أنشئ حساباً» على شاشةِ الدخول — الرابطُ الذي يقررُ أيَّ نموذجٍ يملأُ زائرٌ لا
| حسابَ له.
|
| ⚠️ وكان يشيرُ إلى `/register`: البابَ العديمَ الدور، بابَ الدعوةِ ومؤسِّسِ
| الأكاديمية. لا يسألُ عن تاريخِ ميلادٍ ولا مرحلةٍ ولا منطقة — فالطالبُ القادمُ
| منه يُسجَّلُ بلا سنةٍ ولا منطقةٍ وبلا بوابةِ موافقةِ وليِّ الأمر (`FR-009`)،
| بصمت، وبردٍّ ٢٠١ يبدو سليماً.
|
| الفرعان يُقاسان معاً عمداً: تثبيتُ حالةِ اللادعوةِ وحدَها يمرُّ على بناءٍ
| أزالَ الشرطَ كلَّه وأرسلَ المدعوَّ إلى صفحةِ الاختيار — حيثُ لا نموذجَ يقبلُ
| دعوتَه أصلاً.
*/

const searchParams = new URLSearchParams();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
  useSearchParams: () => searchParams,
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ login: vi.fn() }),
  homePathFor: () => "/dashboard",
}));

afterEach(cleanup);

function signupHref() {
  return screen.getByRole("link", { name: "أنشئ حساباً" }).getAttribute("href");
}

describe("LoginPage", () => {
  it("sends a visitor with no invitation to the role chooser", () => {
    searchParams.delete("invitation");

    render(<LoginPage />);

    expect(signupHref()).toBe("/signup");
  });

  /*
  | الشعارُ مخرَجٌ، لا زينة.
  |
  | ⚠️ الشاشاتُ الثلاثُ التي يلبَسُها `AuthShell` — الدخولُ وتحدّي العاملِ الثاني
  | والتسجيل — لا تحملُ ترويسةً ولا تذييلاً، فالعلامةُ هي الشيءُ الوحيدُ عليها
  | الذي *يبدو* أنّه يقودُ إلى مكان. وكانت تقودُ إلى لا شيء: زائرٌ وصلَ بالخطأ،
  | أو أرادَ قراءةَ الشروطِ قبلَ كتابةِ كلمةِ مرور، لا مخرجَ له إلّا زرُّ الرجوع.
  */
  it("puts a way home behind the mark", () => {
    searchParams.delete("invitation");

    render(<LoginPage />);

    // بالاسمِ المتاح، لا بالمحدِّد: `BrandMarkDecorative` فراغٌ مقنَّع، فاسمُ
    // الرابطِ هو الشيءُ الوحيدُ الذي يسمعُه قارئُ الشاشة.
    const home = screen.getByRole("link", { name: /الصفحة الرئيسية/ });

    expect(home.getAttribute("href")).toBe("/");
  });

  it("keeps the invitation on /register, which is the account it creates", () => {
    searchParams.set("invitation", "tok-9");

    render(<LoginPage />);

    expect(signupHref()).toBe("/register?invitation=tok-9");
  });
});
