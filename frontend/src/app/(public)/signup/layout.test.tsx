import { render, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| مَن يُطرَدُ من نماذجِ التسجيل، ومَن لا يُطرَد.
|
| ⚠️ **الحالةُ السالبةُ هي التي تحرسُ الإصلاح.** استثناءٌ لصاحبِ طلبٍ لم يكتملْ
| مكتوبٌ بلا حذرٍ يفتحُ البابَ للجميع — والنتيجةُ نموذجُ «حساب طالب» يُعرَضُ على
| مدرّسٍ يملكُ حساباً، وهو العطبُ الذي وُجِدَ الحارسُ لأجلِه. فكلُّ حالةٍ هنا
| تُقاسُ بـ`router.replace`: أَحدثَ التحويلُ أم لا. لا بالرسالةِ المنبثقة — تلك
| زينةٌ فوقَ القرار، لا القرارُ نفسُه.
*/
const replace = vi.fn();
const get = vi.fn();
let pathname = "/signup/teacher";
let user: { platform_role: string } | null = null;
/* The provider answers `true` until the token in `localStorage` has been
   exchanged for a profile — or found not to be there. That first settled answer
   is the whole of what separates «arrived signed in» from «signed in here». */
let loading = false;

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  usePathname: () => pathname,
}));

vi.mock("sonner", () => ({ toast: { info: vi.fn() } }));

vi.mock("@/lib/api", () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user, loading }),
  isLearner: () => false,
  panelPathFor: () => "/",
}));

const SignupLayout = (await import("./layout")).default;

function renderLayout() {
  return render(
    <SignupLayout>
      <p>النموذج</p>
    </SignupLayout>,
  );
}

describe("SignupLayout", () => {
  beforeEach(() => {
    replace.mockReset();
    get.mockReset();
    pathname = "/signup/teacher";
    user = null;
    loading = false;
  });

  it("lets a guest fill in any form", async () => {
    renderLayout();

    await waitFor(() => expect(get).not.toHaveBeenCalled());
    expect(replace).not.toHaveBeenCalled();
  });

  it("KEEPS an applicant whose application is still editable", async () => {
    // الوعدُ المطبوعُ فوقَ النموذج: «يمكنك التوقّف والعودة في أي وقت». الخطوةُ
    // الأولى تُنشئُ الحساب، فبدونِ هذا الفرعِ يستحيلُ الوفاءُ به.
    user = { platform_role: "teacher" };
    get.mockResolvedValue({ application: { editable: true, current_step: 2 } });

    renderLayout();

    await waitFor(() => expect(get).toHaveBeenCalledWith("/teacher/application"));
    await waitFor(() => expect(replace).not.toHaveBeenCalled());
  });

  it("redirects one whose application the SERVER says is closed", async () => {
    user = { platform_role: "teacher" };
    get.mockResolvedValue({ application: { editable: false, current_step: 4 } });

    renderLayout();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
  });

  it("redirects when the read is refused — a 403 is not a resumable application", async () => {
    user = { platform_role: "teacher" };
    get.mockRejectedValue(new Error("403"));

    renderLayout();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
  });

  /*
  | ⚠️ الحالةُ التي تمنعُ الإصلاحَ من أن يصيرَ عطباً أوسعَ ممّا أصلح.
  */
  it("still redirects a signed-in visitor on the OTHER signup forms, and asks nothing", async () => {
    user = { platform_role: "teacher" };
    pathname = "/signup/student";

    renderLayout();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
    expect(get).not.toHaveBeenCalled();
  });

  /*
  | ⛔ الحالتانِ الأخيرتانِ زوجٌ، ولا تُقرأُ واحدةٌ منهما وحدَها.
  |
  | الحارسُ لمن **يصلُ** مسجَّلاً، لا لمن سجَّلَ هنا. وبلا الثانيةِ يُطرَدُ وليُّ
  | الأمرِ من `‎/signup/parent/children` — الخطوةِ التي سلَّمتْه إيّاها الاستمارةُ
  | قبلَها بسطر، وصفحتُها تقولُ عن نفسِها «لا تُفتَحُ إلّا بجلسة» — ويُسحَبُ
  | الطالبُ من وجهةِ `next` التي بدأَ التسجيلَ من أجلِها. وبلا الأولى يُفتَحُ
  | البابُ للجميعِ ويعودُ العطبُ الذي وُجِدَ الحارسُ له.
  |
  | ⚠️ **وقِيسَ بالتحوير: حذفُ الشرطِ من أثرِ التحويلِ وحدَه يمرُّ أخضر.** الشرطُ
  | مكتوبٌ في أثرَين — سؤالِ الخادمِ وأثرِ التحويلِ — والأوّلُ يتركُ `resumable`
  | على `null` فيرتدُّ الثاني قبلَ أن يُحوِّل. فالحالةُ تعضُّ عندَ حذفِ الاثنَين
  | معاً لا أحدِهما، وهي قاعدةُ هذا المستودعِ نفسُها: اختبارُ شرطٍ واحدٍ من عدّةٍ
  | يقيسُ أيَّها يقعُ أوّلاً ما لم تُحيَّدِ البقيّة.
  */
  it("bounces a visitor who ARRIVED signed in — the answer settles after the read", async () => {
    user = null;
    loading = true;
    pathname = "/signup/student";

    const { rerender } = renderLayout();

    // `auth.me()` came back: the token was already in this browser.
    user = { platform_role: "teacher" };
    loading = false;
    rerender(<SignupLayout><p>النموذج</p></SignupLayout>);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
  });

  it("does NOT bounce a session created on this page", async () => {
    user = null;
    loading = false;
    pathname = "/signup/parent";

    const { rerender } = renderLayout();

    // The form registered the account and handed it to the provider.
    user = { platform_role: "student" };
    rerender(<SignupLayout><p>النموذج</p></SignupLayout>);

    await waitFor(() => expect(get).not.toHaveBeenCalled());
    expect(replace).not.toHaveBeenCalled();
  });
});

/*
| ⛔ **والنموذجُ لا يُرسَمُ لمن سيُنقَل — وهذا ما لم تكنْ تقيسُه أيُّ حالةٍ أعلاه.**
|
| كلُّها تسألُ «أحدثَ التحويل؟»، والتحويلُ كانَ يحدثُ من قبلُ كذلك: صاحبُ الحسابِ
| يرى استمارةَ تسجيلٍ كاملةً تومضُ ثمّ تختفي. فالقياسُ هنا على ما يُرسَمُ على
| الشاشةِ لا على ما يُستدعى بعدَه.
*/
describe("what the page paints while it decides", () => {
  beforeEach(() => {
    replace.mockReset();
    get.mockReset();
    pathname = "/signup/student";
    user = null;
    loading = false;
  });

  it("paints no form for a visitor who arrived signed in", async () => {
    user = { platform_role: "student" };

    const { queryByText } = renderLayout();

    expect(queryByText("النموذج")).toBeNull();
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/"));
    expect(queryByText("النموذج")).toBeNull();
  });

  it("paints no form while the session is still being restored", () => {
    /*
      `loading` جوابٌ ثالثٌ لا «ضيف»: الرمزُ في `localStorage` لم يُبادَلْ بعدُ،
      فرسمُ النموذجِ الآنَ رهانٌ على أنّ الطارقَ ضيف.
    */
    loading = true;
    user = null;

    expect(renderLayout().queryByText("النموذج")).toBeNull();
  });

  it("paints the form for a guest with nothing to restore", () => {
    expect(renderLayout().queryByText("النموذج")).toBeTruthy();
  });

  it("paints the form for somebody who made their account on this page", async () => {
    /*
      ⛔ الحالةُ التي يكسرُها أيُّ حارسٍ مكتوبٍ بلا حذر: وليُّ الأمرِ يُنشئُ
      حسابَه ثمّ يُنقَلُ إلى «أبنائي» — وهي تحتَ هذا التخطيطِ نفسِه. فلو أمسكَ
      الرسمَ لمن «مسجَّلُ الدخول» أطلقَ، لاختفت الخطوةُ الثانيةُ في وجهِه.
    */
    pathname = "/signup/parent/children";

    const view = renderLayout();

    await waitFor(() => expect(view.queryByText("النموذج")).toBeTruthy());

    user = { platform_role: "guardian" };
    view.rerender(
      <SignupLayout>
        <p>النموذج</p>
      </SignupLayout>,
    );

    expect(view.queryByText("النموذج")).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });
});
