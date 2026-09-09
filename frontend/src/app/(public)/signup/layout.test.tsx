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

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  usePathname: () => pathname,
}));

vi.mock("sonner", () => ({ toast: { info: vi.fn() } }));

vi.mock("@/lib/api", () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user }),
  isLearner: () => false,
  panelPathFor: () => "/dashboard",
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

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dashboard"));
  });

  it("redirects when the read is refused — a 403 is not a resumable application", async () => {
    user = { platform_role: "teacher" };
    get.mockRejectedValue(new Error("403"));

    renderLayout();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dashboard"));
  });

  /*
  | ⚠️ الحالةُ التي تمنعُ الإصلاحَ من أن يصيرَ عطباً أوسعَ ممّا أصلح.
  */
  it("still redirects a signed-in visitor on the OTHER signup forms, and asks nothing", async () => {
    user = { platform_role: "teacher" };
    pathname = "/signup/student";

    renderLayout();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dashboard"));
    expect(get).not.toHaveBeenCalled();
  });
});
