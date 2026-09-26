import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { formatMinorMoney } from "@/lib/labels";

/*
| 027 — THE REFUSAL IS AT THE TOP AND THE SEND BUTTON IS AT THE BOTTOM.
|
| Reported from a real purchase (2026-09-06): the server refused with 422, the
| danger Alert rendered exactly as designed — and the buyer, standing on the
| button, saw NOTHING happen and pressed it again. A message nobody can see is a
| swallowed error wearing markup.
|
| ⚠️ `scrollIntoView` DOES NOT EXIST IN JSDOM, so it is stubbed rather than
| asserted through a real scroll; what is measured is that the page ASKS for the
| sentence to be shown, which is the half that was missing.
*/

const create = vi.fn();
const uploadReceipt = vi.fn();

/* الحسابُ القارئُ — يُبدَّلُ لكلِّ حالة؛ الافتراضُ طالبٌ يشتري لنفسِه. */
let mockUser: Record<string, unknown> = { platform_role: "student" };

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: mockUser }),
}));

const listRelations = vi.fn();

vi.mock("@/lib/notifications", () => ({
  family: { list: () => listRelations() },
}));

/* العنوانُ الذي فُتِحَت به الصفحة — يُبدَّلُ في حالةِ «بلا كورس» وحدَها. */
let mockSearch = "course=course-uuid&cohort=cohort-uuid";

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(mockSearch),
}));

/** الباقةُ بالشهر — الشكلُ الذي شحنَه ٠٢٧، وهو افتراضُ كلِّ حالةٍ أدناه. */
const MONTH_PLAN = {
  uuid: "plan-uuid",
  title: "الشهري",
  duration_days: 30,
  session_count: null,
  session_type: "group",
  price_minor: 45_000,
  currency: "QAR",
};

/* ما سُئِلَ عنه الخادمُ فعلاً — قائمةُ وسائطِ كلِّ نداءٍ لقارئِ الباقات. */
const plansAsked: unknown[][] = [];

/* ما يردُّه الخادمُ من باقات — يُبدَّلُ في حالاتِ الشكلَينِ وحدَها. */
let mockPlans: Record<string, unknown>[] = [MONTH_PLAN];

vi.mock("@/lib/subscribe", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/subscribe")>();

  return {
    ...actual,
    subscribe: {
      course: vi.fn(async () => ({
        data: {
          uuid: "course-uuid",
          title: "أساسيّات التفاضل",
          teacher: { name: "Demo Teacher" },
          cohorts: [{ uuid: "cohort-uuid", name: "مجموعة السبت", schedule: [] }],
        },
      })),
      plans: vi.fn(async (...args: unknown[]) => {
        plansAsked.push(args);

        return { data: mockPlans };
      }),
      create: (body: unknown) => create(body),
      uploadReceipt: (...args: unknown[]) => uploadReceipt(...args),
    },
  };
});

async function fillAndSend() {
  const { default: SubscribePage } = await import("./page");

  render(<SubscribePage />);

  const plan = await screen.findByRole("radio");

  fireEvent.click(plan);

  const receipt = document.querySelector<HTMLInputElement>("#subscribe-receipt");

  fireEvent.change(receipt as HTMLInputElement, {
    target: { files: [new File(["x"], "receipt.png", { type: "image/png" })] },
  });

  fireEvent.click(screen.getByRole("button", { name: "أرسِلِ الطلب" }));
}

describe("the subscription screen's refusal", () => {
  beforeEach(() => {
    vi.resetModules();
    create.mockReset();
    uploadReceipt.mockReset();
    listRelations.mockReset();
    listRelations.mockResolvedValue({ data: [] });
    mockUser = { platform_role: "student" };
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("brings the reason into view instead of leaving it above the fold", async () => {
    create.mockRejectedValue(
      Object.assign(new Error("refused"), {
        status: 422,
        message: "هذه المجموعة لم تعد متاحة للانضمام.",
      }),
    );

    await fillAndSend();

    /*
      ⚠️ BOTH FACTS INSIDE THE WAIT. The scroll happens in an effect that runs
      AFTER the refusal is painted, so asserting it beside the wait rather than
      inside it is a race the test loses on a slow machine — and it did, on CI.
    */
    await waitFor(() => {
      expect(screen.getByText("لم يُرسل الطلب")).toBeDefined();
      expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    });
  });

  /*
  | ⛔ الطلبُ الذي وُلِدَ ثمّ تعثَّرَ إيصالُه — بلاغُ مشيٍ حقيقيٍّ ٢٠٢٦-٠٩-١٦.
  |
  | الإرسالُ خطوتان: `create` ثمّ `uploadReceipt`. حينَ تنجحُ الأولى وتُخفِقُ
  | الثانيةُ كانَ المُلتَقَطُ واحداً فتُقالُ الجملةُ نفسُها «لم يُرسل الطلب» —
  | عن طلبٍ مكتوبٍ يقعدُ في طابورِ الموظّفِ بلا إيصال. قِيسَ: `201` ثمّ `422`
  | ⇐ الطلبُ #٩ موجودٌ والشاشةُ تقولُ إنّ شيئاً لم يُرسَل.
  |
  | ⚠️ **وشقّانِ ضدّان**: الإخفاقُ قبلَ الإنشاءِ يبقى «لم يُرسل» — وهو صادقٌ
  | هناك — والإخفاقُ بعدَه يقولُ ما وقع. وشقٌّ واحدٌ يمرُّ على بناءٍ يقولُ
  | الجملةَ الجديدةَ لكلِّ رفض.
  */
  it("says the order arrived when only the receipt was refused", async () => {
    create.mockResolvedValue({ data: { uuid: "order-uuid" } });
    uploadReceipt.mockRejectedValue(
      Object.assign(new Error("refused"), { status: 422, message: "حجم الملف كبير." }),
    );

    await fillAndSend();

    await waitFor(() => {
      expect(screen.getByText("طلبك وصل — والإيصال لم يُرفع")).toBeDefined();
    });

    expect(screen.queryByText("لم يُرسل الطلب")).toBeNull();
  });

  /*
  | ⚠️ وإعادةُ الإرسالِ ترفعُ على الطلبِ نفسِه ولا تُنشئُ ثانياً. وبدونِ هذه
  | الحالةِ يمرُّ بناءٌ يقولُ الجملةَ الصحيحةَ ثمّ يُولِّدُ صفّاً جديداً في
  | الطابورِ مع كلِّ محاولة.
  */
  it("retries the receipt onto the same order, and raises no second one", async () => {
    create.mockResolvedValue({ data: { uuid: "order-uuid" } });
    uploadReceipt.mockRejectedValueOnce(
      Object.assign(new Error("refused"), { status: 422, message: "حجم الملف كبير." }),
    );

    await fillAndSend();

    await waitFor(() => {
      expect(screen.getByText("طلبك وصل — والإيصال لم يُرفع")).toBeDefined();
    });

    /*
      ⚠️ ضغطةٌ ثانيةٌ على الرسمِ نفسِه، لا نداءٌ ثانٍ لـ`fillAndSend` — ذاك يرسمُ
      الصفحةَ من جديدٍ فيصيرُ في المستندِ زرّانِ بالاسمِ نفسِه، ويسقطُ الاختبارُ
      برسالةٍ عن المُنتقي لا عن السلوك. والملفُّ ما زالَ في الحالةِ بعدَ الرفض.
    */
    uploadReceipt.mockResolvedValue({});
    fireEvent.click(screen.getByRole("button", { name: "أرسِلِ الطلب" }));

    await waitFor(() => {
      expect(screen.getByText("وصل طلبك")).toBeDefined();
    });

    expect(create).toHaveBeenCalledTimes(1);
    expect(uploadReceipt).toHaveBeenCalledTimes(2);
  });

  it("scrolls nothing when the request went through", async () => {
    create.mockResolvedValue({ data: { uuid: "order-uuid" } });
    uploadReceipt.mockResolvedValue({});

    await fillAndSend();

    await waitFor(() => {
      expect(screen.getByText("وصل طلبك")).toBeDefined();
    });

    expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
  });
});

/*
|------------------------------------------------------------------------------
| A GUARDIAN SUBSCRIBES FOR A CHILD — reported by the user on 2026-09-08.
|------------------------------------------------------------------------------
|
| «منطقياً ماينفعش» — the screen sent the session's own account as the student,
| so a guardian who pressed «اشترك» became the learner: the enrolment and the
| group membership were written in their name and the child held nothing. The
| server refuses that now; these cases are the half that lets the payer pay.
|
| ⚠️ THE FILTER IS THE POINT OF THE FIRST TWO. An option the server will refuse
| is worse than no option — it is a refusal the reader cannot act on — and the
| two conditions guard two different refusals: a child with no account has
| nothing to enrol, and a relation without «payments» is one the server denies.
*/
const CHILD = {
  uuid: "rel-1",
  status: "active",
  student_name: "كريم",
  student_uuid: "child-1",
  permissions: [{ key: "payments", label: "المدفوعات" }],
};

async function openAsGuardian(relations: unknown[]) {
  mockUser = { platform_role: "parent" };
  listRelations.mockResolvedValue({ data: relations });

  const { default: SubscribePage } = await import("./page");

  render(<SubscribePage />);
}

describe("the subscription screen for a guardian", () => {
  beforeEach(() => {
    vi.resetModules();
    create.mockReset();
    uploadReceipt.mockReset();
    listRelations.mockReset();
    mockUser = { platform_role: "student" };
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("sends the chosen child, and will not send without one", async () => {
    create.mockResolvedValue({ data: { uuid: "order-uuid" } });
    uploadReceipt.mockResolvedValue({});

    await openAsGuardian([CHILD]);

    const plan = await screen.findByRole("radio");

    fireEvent.click(plan);
    fireEvent.change(document.querySelector("#subscribe-receipt") as HTMLInputElement, {
      target: { files: [new File(["x"], "receipt.png", { type: "image/png" })] },
    });

    // ⚠️ الزرُّ معطَّلٌ قبلَ اختيارِ الطالبِ رغمَ اكتمالِ الباقةِ والإيصال: الخادمُ
    // يرفضُ الفارغَ، وزرٌّ نشطٌ يقودُ إلى ٤٢٢ عن حقلٍ لم يُطلَبْ ملؤُه بعد.
    const send = screen.getByRole("button", { name: "أرسِلِ الطلب" });

    expect((send as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText(/لمن هذا الاشتراك/), {
      target: { value: "child-1" },
    });
    fireEvent.click(send);

    await waitFor(() => {
      expect(create).toHaveBeenCalled();
    });

    expect(create.mock.calls[0][0].student_uuid).toBe("child-1");
  });

  it("offers no child the server would refuse, and says what to do instead", async () => {
    await openAsGuardian([
      // أُضيفَ بالاسمِ ولم يفتحْ حساباً — لا حسابَ يُسجَّلُ فيه.
      { ...CHILD, uuid: "rel-2", student_name: "بدر", student_uuid: undefined },
      // وصايةٌ بلا صلاحيّةِ دفع — الخادمُ يرفضُها بجملةٍ واحدة.
      {
        ...CHILD,
        uuid: "rel-3",
        student_name: "سارة",
        student_uuid: "child-3",
        permissions: [{ key: "attendance", label: "الحضور" }],
      },
    ]);

    expect(await screen.findByText("لا يوجد ابن يمكنك الاشتراك له")).toBeDefined();
    expect(screen.queryByLabelText(/لمن هذا الاشتراك/)).toBeNull();
    expect(screen.getByRole("link", { name: "المرتبطون" }).getAttribute("href")).toBe("/family");
  });

  it("asks a student buying for themselves for nobody", async () => {
    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    await screen.findByRole("radio");

    expect(screen.queryByLabelText(/لمن هذا الاشتراك/)).toBeNull();
    // ولا يُسألُ الخادمُ عن أبناءٍ لحسابٍ ليسَ وليَّ أمر.
    expect(listRelations).not.toHaveBeenCalled();
  });
});

/*
| ٠٣٦ · US2 · T063 — الشاشةُ تصفُ كلَّ شكلٍ بصدقِه.
|
| ⛔ **وبعددٍ في نطاقِ التثنيةِ أو ٣–١٠، لا ١٢ ولا ٣٠.** الاثنا عشرَ والثلاثونَ
| هما بالضبطِ النطاقُ («many») الذي يتّفقُ فيه قالبٌ نصّيٌّ ساذجٌ مع قاعدةِ
| العدِّ العربيّة، فحالةٌ مكتوبةٌ بأحدِهما تمرُّ فوقَ «٣ حصّة» و«حصّتان» معاً.
*/
describe("what the buyer is told a plan sells", () => {
  beforeEach(() => {
    vi.resetModules();
  });

  afterEach(() => {
    mockPlans = [MONTH_PLAN];
  });

  it("names the months for a plan sold by time", async () => {
    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    await screen.findByRole("radio");

    expect(screen.getByText("شهر واحد · حصص جماعية")).toBeDefined();
  });

  it("names the COUNT for a plan sold by sessions, and never a duration", async () => {
    mockPlans = [
      { ...MONTH_PLAN, uuid: "plan-two", title: "باقة الحصص", duration_days: null, session_count: 2 },
      { ...MONTH_PLAN, uuid: "plan-three", title: "باقة الثلاث", duration_days: null, session_count: 3 },
    ];

    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    await screen.findAllByRole("radio");

    // «حصّتان» بلا رقم — العربيّةُ تحملُ العدَّ في الكلمةِ نفسِها — و«٣ حصص»
    // بالرقم. القاعدةُ في `counted()` وحدَها، ولا تُهجّى هنا مرّةً ثانية.
    expect(screen.getByText("حصّتان · حصص جماعية")).toBeDefined();
    expect(screen.getByText("٣ حصص · حصص جماعية")).toBeDefined();

    // ⛔ ولا كلمةَ «يوماً» في الصفحةِ كلِّها: `null % 30 === 0` صحيحٌ في
    // JavaScript، فالسقوطُ القديمُ كانَ يطبعُ «null يوماً» لمشترٍ يقرأُ سعراً.
    expect(document.body.textContent).not.toContain("يوماً");
    expect(document.body.textContent).not.toContain("null");
  });
});

/*
| ٠٣٦ · US3 · T089 — المجموعةُ تُمرَّرُ إلى قارئِ الباقات.
|
| ⛔ **ويُقاسُ ما طُلِبَ من الخادمِ لا ما رُسِمَ على الشاشة.** الاستبدالُ يقعُ
| في الخادم؛ ما تملكُه هذه الشاشةُ هو أن تقولَ له على أيِّ مجموعةٍ يقفُ
| المشتري. فتأكيدٌ على الباقاتِ المعروضةِ يقيسُ عيّنةَ الاختبارِ لا الشاشة،
| ويبقى أخضرَ وقد سقطَ الوسيطُ بالكامل — وحينَها يعرِضُ المنتَجُ باقةَ الكورسِ
| ويرفضُها البابُ بجملةٍ عن المجموعة.
*/
describe("what the screen asks the server for", () => {
  beforeEach(() => {
    vi.resetModules();
    plansAsked.length = 0;
  });

  it("names the group the buyer is standing on", async () => {
    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    await screen.findByRole("radio");

    expect(plansAsked).toHaveLength(1);
    // الكورسُ · نوعُ الحصّةِ · والمجموعةُ — الثالثُ هو ما أضافَه ٠٣٦.
    expect(plansAsked[0]).toEqual(["course-uuid", "group", "cohort-uuid"]);
  });
});

/*
| ⛔ بلاغُ ٢٠٢٦-٠٩-٢٤: `/subscribe` بلا `?course=` كانت تقولُ «تعذّر تحميل
| البيانات» — خطأٌ لم يقعْ، وزرُّ «إعادة المحاولة» لا يُصلِحُ شيئاً. الصفحةُ
| تقولُ من أين يبدأُ الاشتراكُ وتدلُّ عليه.
*/
describe("the subscription screen opened with no course", () => {
  beforeEach(() => {
    vi.resetModules();
    mockUser = { platform_role: "student" };
    mockSearch = "";
  });

  afterEach(() => {
    mockSearch = "course=course-uuid&cohort=cohort-uuid";
  });

  it("points to the courses instead of reporting a failure", async () => {
    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    expect(await screen.findByText("اختر كورساً أولاً")).toBeTruthy();
    expect(screen.getByRole("link", { name: "تصفّح الكورسات" }).getAttribute("href")).toBe(
      "/courses",
    );
    expect(screen.queryByText(/تعذّر/)).toBeNull();
    expect(screen.queryByRole("button", { name: /إعادة المحاولة/ })).toBeNull();
  });
});

/*
| بلاغُ ٢٠٢٦-٠٩-٢٦ — «حوِّلْ قيمة الباقة إلى حساب المنصّة» تحتاجُ الحسابَ والمبلغَ
| معاً في موضعِ الجملة، قبلَ خانةِ الإيصال: لا صفحةَ طلباتٍ بعدُ لمن لم يُرسِلْ.
*/
vi.mock("@/lib/billing", () => ({
  billing: {
    transferInstructions: () =>
      Promise.resolve({
        data: { bank_name: "بنك الدوحة", iban: "QA00TEST0000000000000000001" },
        configured: true,
      }),
  },
}));

describe("where and how much the buyer transfers", () => {
  beforeEach(() => {
    vi.resetModules();
    mockUser = { platform_role: "student" };
  });

  afterEach(() => {
    mockSearch = "course=course-uuid&cohort=cohort-uuid";
  });

  it("prints the account above the receipt field, and the chosen plan's exact sum", async () => {
    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    const iban = await screen.findByText("QA00TEST0000000000000000001");
    const receipt = document.querySelector("#subscribe-receipt") as HTMLElement;

    // Before the receipt field in reading order, not below it.
    expect(iban.compareDocumentPosition(receipt) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    const sum = screen.getByRole("group", { name: "المبلغ المطلوب تحويله" });

    expect(sum.textContent).toContain("اختر الباقة أولاً");

    fireEvent.click(screen.getByRole("radio"));

    expect(sum.textContent).toContain(formatMinorMoney(MONTH_PLAN.price_minor, "QAR"));
    // There is no table on this page to upload from.
    expect(screen.queryByText(/من الجدول/)).toBeNull();
  });

  it("arrives with the plan pressed on /plans already chosen", async () => {
    mockSearch = "course=course-uuid&cohort=cohort-uuid&plan=plan-uuid";

    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    const radio = (await screen.findByRole("radio")) as HTMLInputElement;

    await waitFor(() => expect(radio.checked).toBe(true));
  });

  it("chooses nothing for a plan that is no longer on offer", async () => {
    mockSearch = "course=course-uuid&cohort=cohort-uuid&plan=withdrawn-plan";

    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    const radio = (await screen.findByRole("radio")) as HTMLInputElement;

    expect(radio.checked).toBe(false);
  });
});
