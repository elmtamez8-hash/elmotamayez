import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import PurchaseCreditsPage from "./page";

/*
|------------------------------------------------------------------------------
| «شراء أرصدة» — من، ثمّ أيُّ كورس، ثمّ كم (٠٣١ · US3).
|------------------------------------------------------------------------------
|
| ⚠️ **قائمةُ النداءاتِ هي المقياس، لا ما يظهرُ على الشاشة.** قاعدةٌ وهميّةٌ
| تُجيبُ كلَّ مسارٍ بنجاحٍ تُخرِجُ شاشةً خضراءَ لا تقولُ شيئاً عن أيِّ بابٍ طُرِق —
| وهذه الشاشةُ بالذاتِ كانت تنادي `‎/enrollments` وتُرشِّحُ في المتصفّح، وهو ما
| تمنعُه `FR-018` بالاسم. فالمقياسُ «أيَّ بابٍ طرقتَ» لا «هل ظهرَ شيء».
|
| ⚠️ **و`‎/enrollments` تحديداً محظور**: `isPartyTo` ثلاثُ أذرعٍ وقائمةُ
| التسجيلاتِ تغطّي واحدة، فمنتقٍ مبنيٌّ عليها يُخفي كورساتٍ يقبلُها الخادمُ —
| `SC-003` مكسورةٌ في اتّجاهِها الصامت، حيثُ كلُّ خيارٍ معروضٍ يعملُ ولا أحدَ
| يلاحظُ الغائب.
*/

const get = vi.fn();
const post = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: (path: string, body: unknown) => post(path, body),
  },
}));

let mockUser: Record<string, unknown> = { platform_role: "student" };

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

let mockParams = new URLSearchParams();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
  useSearchParams: () => mockParams,
}));

function asStudent() {
  mockUser = { platform_role: "student", permissions: [] };
}

function asGuardian() {
  mockUser = { platform_role: "parent", permissions: [] };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockParams = new URLSearchParams();
  asStudent();

  // ⚠️ الرفضُ هو الافتراض. قاعدةٌ تقبلُ كلَّ مسارٍ تُخفي بابَينِ خاطئَين.
  get.mockImplementation(() => Promise.reject(new Error("403")));
});

describe("الطالب لنفسه", () => {
  it("does not ask who is paying, and reads the courses door — never /enrollments", async () => {
    asStudent();
    get.mockImplementation((path: string) =>
      path.startsWith("/billing/purchasable-courses")
        ? Promise.resolve({ data: [{ uuid: "c-1", title: "الفيزياء ٣", teacher_name: "أكاديمية خالد", cover_url: null }] })
        : Promise.reject(new Error("unexpected " + path)),
    );

    render(<PurchaseCreditsPage />);

    await waitFor(() => expect(screen.getByText("الفيزياء ٣")).toBeTruthy());

    const asked = get.mock.calls.map((call) => String(call[0]));

    expect(asked.some((path) => path.startsWith("/billing/purchasable-courses"))).toBe(true);
    // ⛔ العطبُ الذي شحنَ: منتقٍ من تسجيلاتِ القارئ.
    expect(asked.some((path) => path.startsWith("/enrollments"))).toBe(false);
    // ولا منتقيَ أبناءٍ لطالبٍ يشتري لنفسِه.
    expect(asked.some((path) => path.startsWith("/billing/beneficiaries"))).toBe(false);
  });

  it("sends no student_uuid at all when buying for itself", async () => {
    asStudent();
    mockParams = new URLSearchParams({ course: "c-1" });

    get.mockImplementation(() =>
      Promise.resolve({
        data: [{ uuid: "p-1", name: "أربع حصص", credits: 4, total_minor: 20000, currency: "QAR", validity_days: null }],
      }),
    );
    post.mockResolvedValue({ order: "o-1" });

    render(<PurchaseCreditsPage />);

    await waitFor(() => expect(screen.getByText("أربع حصص")).toBeTruthy());

    // الحقلُ غائبٌ لا `null`: الغيابُ يعني «أنا»، وهو ما عنَتْه كلُّ طلباتِ ما قبلَ ٠٣١.
    expect(get.mock.calls[0][0]).toBe("/billing/packages?course=c-1");

    screen.getByRole("button", { name: "اختيار هذه الحزمة" }).click();

    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post.mock.calls[0][1]).toEqual({ course: "c-1", package: "p-1" });
  });
});

describe("وليّ الأمر", () => {
  it("asks who is paying first, from the server list and not from /family/relations", async () => {
    asGuardian();
    get.mockImplementation((path: string) =>
      path === "/billing/beneficiaries"
        ? Promise.resolve({ data: [{ uuid: "s-1", name: "كريم" }] })
        : Promise.reject(new Error("unexpected " + path)),
    );

    render(<PurchaseCreditsPage />);

    await waitFor(() => expect(screen.getByText("كريم")).toBeTruthy());

    const asked = get.mock.calls.map((call) => String(call[0]));

    expect(asked).toContain("/billing/beneficiaries");
    /*
     * ⛔ الترشيحُ في TypeScript هو ما تمنعُه `FR-018`. وقد كانَ مشحوناً بهجاءَين:
     * `ChildSwitcher` يُرشِّحُ بـ`status` و`student_uuid` **بلا أيِّ ترشيحٍ
     * للصلاحيّات**، وشاشةُ الاشتراكِ تُرشِّحُ بها — إجابتانِ لسؤالٍ واحد.
     */
    expect(asked.some((path) => path.startsWith("/family/relations"))).toBe(false);
    // ولا كورساتٍ قبلَ أن يُعرَفَ الابن: الخادمُ نفسُه يردُّ ٤٢٢ بلا اسم.
    expect(asked.some((path) => path.startsWith("/billing/purchasable-courses"))).toBe(false);
  });

  it("shows a reason and a way out when no child is payable (FR-013)", async () => {
    asGuardian();
    get.mockImplementation(() => Promise.resolve({ data: [] }));

    render(<PurchaseCreditsPage />);

    await waitFor(() => expect(screen.getByText("لا يوجد ابن يمكنك الشراء له")).toBeTruthy());

    // ⚠️ سببٌ وطريقُ علاجٍ لا نموذجٌ فارغ: القائمةُ الفارغةُ سببُها ارتباطٌ لم
    // يقبلْه الابنُ بعد، أو ابنٌ مضافٌ بالاسمِ بلا حساب — وكلاهما يُعالَجُ من
    // «المرتبطون».
    const link = screen.getByRole("link", { name: "المرتبطون" });

    expect(link.getAttribute("href")).toBe("/family");
  });

  it("carries the child through the courses door and into the purchase", async () => {
    asGuardian();
    mockParams = new URLSearchParams({ student: "s-1", course: "c-1" });

    get.mockImplementation(() =>
      Promise.resolve({
        data: [{ uuid: "p-1", name: "أربع حصص", credits: 4, total_minor: 20000, currency: "QAR", validity_days: null }],
      }),
    );
    post.mockResolvedValue({ order: "o-1" });

    render(<PurchaseCreditsPage />);

    await waitFor(() => expect(screen.getByText("أربع حصص")).toBeTruthy());

    // التسعيرُ محروسٌ كالشراء، فالابنُ مُسمّىً على البابَين معاً.
    expect(get.mock.calls[0][0]).toBe("/billing/packages?course=c-1&student_uuid=s-1");

    screen.getByRole("button", { name: "اختيار هذه الحزمة" }).click();

    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post.mock.calls[0][1]).toEqual({ course: "c-1", package: "p-1", student_uuid: "s-1" });
  });

  it("asks the courses door for the CHILD, once one is chosen", async () => {
    asGuardian();
    mockParams = new URLSearchParams({ student: "s-1" });

    get.mockImplementation((path: string) =>
      path.startsWith("/billing/purchasable-courses")
        ? Promise.resolve({ data: [{ uuid: "c-9", title: "الكيمياء ١", teacher_name: "أكاديمية خالد", cover_url: null }] })
        : Promise.reject(new Error("unexpected " + path)),
    );

    render(<PurchaseCreditsPage />);

    await waitFor(() => expect(screen.getByText("الكيمياء ١")).toBeTruthy());

    expect(get.mock.calls[0][0]).toBe("/billing/purchasable-courses?student_uuid=s-1");

    /*
     * ⚠️ **والكورسُ المعروضُ لا يُرشَّحُ هنا بحال.** «الكيمياء ١» كورسٌ لا تسجيلَ
     * للابنِ فيه — تفتحُه الذراعُ الثانيةُ من `isPartyTo` (تسجيلٌ نشطٌ في أيِّ
     * كورسٍ بمساحةِ المدرّس) — فالخادمُ وحدَه يعرفُ أنّه مقبول.
     */
    expect(screen.getByRole("link", { name: "عرض الحزم" }).getAttribute("href"))
      .toBe("/billing/purchase?student=s-1&course=c-9");
  });
});
