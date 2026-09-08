import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import DashboardPage from "./page";
import { UNKNOWN_MESSAGE } from "@/lib/errors";

/*
| «لوحة التحكم» — أوّلُ شاشةٍ بعدَ تسجيلِ الدخول، **ولا صلاحيةَ عليها في القائمة**:
| يصلُها الطالبُ ووليُّ الأمرِ والمدرّسُ والمساعد. فكلُّ قراءةٍ فيها يجبُ أن تكون
| قراءةً يملكُها الجميع.
|
| ⚠️ وكانت لا تفعل. الكرتُ الرابعُ ينادي `/courses` — فهرسَ التأليف، و
| `CoursePolicy::viewAny()` يشترطُ `courses.view`، وهي صلاحيةُ مساحةِ عمل: الطالبُ
| عضوٌ في لا مساحة، فسياقُه `null`، ومُعرِّفُ فريقِ spatie `null`، وكلُّ `can()`
| تحتَه false. ٤٠٣ لكلِّ طالبٍ ولكلِّ وليِّ أمرٍ على المنصّة — والقراءاتُ الثلاثُ
| السليمةُ تشاركُه `Promise.all` واحداً، فسقطت اللوحةُ **كلُّها** إلى «تعذّر تحميل
| البيانات» منذُ شُحِنت.
|
| الاختبارُ يقيسُ المسارَ المطلوبَ لا الشاشةَ وحدَها: شاشةٌ خضراءُ على قاعدةٍ
| تُجيبُ كلَّ نداءٍ بنجاحٍ لا تقولُ شيئاً عن أيِّ بابٍ طُرِق.
*/

const get = vi.fn();

// ⚠️ `importActual` مع استبدالِ `api` وحدَه: `lib/errors.ts` يستوردُ `ApiError`
// ويسألُ عنه بـ`instanceof` عندَ **كلِّ** رفض، و`auth-context` يستوردُ أربعَ
// دوالَّ أخرى. مصنعٌ يُصدِّرُ `api` فقط ينفجرُ عندَ أوّلِ نداءٍ مرفوضٍ برسالةٍ
// عن التهيئةِ لا عن الشاشة.
vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path) },
}));

// `importActual` for everything but `useAuth`: this page branches on
// `isLearner()` to aim the certificates panel at the learner's screen or the
// teacher's, and a wholesale mock would replace the predicate under test with
// nothing.
//
// ⚠️ والحسابُ متغيّرٌ الآن لا ثابت: الصفحةُ صارت مُوجِّهاً، فالدورُ هو المُدخَلُ
// الأوّلُ لكلِّ حالةٍ تحتَه.
let mockUser: Record<string, unknown> = { first_name: "سلمى", platform_role: "student" };

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

beforeEach(() => {
  vi.clearAllMocks();

  /*
  | افتراضٌ محايدٌ لا حالة: كلُّ وصفٍ تحتَه يُنادي `asStudent()` أو `asTeacher()`
  | أو `asGuardian()` في أوّلِ سطرٍ منه، فيُثبِّتُ حسابَه وقاعدتَه معاً.
  |
  | ⚠️ **والرفضُ هو الافتراضُ لا النجاح**: قاعدةٌ تُجيبُ كلَّ مسارٍ بنجاحٍ تُخرِجُ
  | شاشةً خضراءَ لا تقولُ شيئاً عن أيِّ بابٍ طُرِق — وهو العطلُ المشحونُ الذي
  | شُرِحَ في صدرِ هذا الملفّ.
  */
  mockUser = { first_name: "سلمى", platform_role: "student", permissions: [] };
  get.mockImplementation(() => Promise.reject(new Error("403")));
});

/*
|------------------------------------------------------------------------------
| ٠٢٩ · US1 — الطالب
|------------------------------------------------------------------------------
|
| ⚠️ قائمةُ النداءاتِ هي المقياس. شاشةٌ خضراءُ على قاعدةٍ تُجيبُ كلَّ مسارٍ
| بنجاحٍ لا تقولُ شيئاً عن أيِّ بابٍ طُرِق — وهو العطلُ المشحونُ هنا بعينِه.
*/

const SESSION = {
  uuid: "s-1",
  title: "المتجهات",
  type: "group",
  type_label: "جماعية",
  status: "scheduled",
  status_label: "مجدولة",
  room_closed: false,
  join_open: false,
  seconds_until_join_open: 3600,
  seconds_until_start: 4500,
  starts_at: "2026-09-09T09:00:00+03:00",
  ends_at: "2026-09-09T10:00:00+03:00",
  duration_minutes: 60,
  timezone: "Asia/Qatar",
  seats: { total: 10, taken: 3, available: 7 },
  my_booking: null,
  course: { uuid: "c-1", title: "الفيزياء" },
  teacher_name: "أ. منى",
  recording: null,
};

function balanceRow(over: Record<string, unknown> = {}) {
  return {
    uuid: "bal-1",
    course: { uuid: "c-1", title: "الفيزياء", teacher_name: "أ. منى" },
    purchased_credits: 10,
    consumed_credits: 4,
    remaining_credits: 6,
    credit_limit_credits: 0,
    is_withheld: false,
    credits_needed: 0,
    ...over,
  };
}

function studentAnswer(overrides: Record<string, unknown> = {}) {
  return (path: string) => {
    if (path in overrides) return overrides[path] as Promise<unknown>;

    if (path === "/enrollments?status=active") {
      /*
      | خمسةَ عشرَ صفّاً ومجموعٌ أكبرُ منها: الفرقُ بينَ `meta.total` و`data.length`
      | — وهو نفسُه السقفُ الذي يرسمُه `ProgressChartCard` عيّنةً لا جرداً.
      |
      | والصفوفُ **تسجيلاتٌ حقيقيّةٌ لا كائناتٌ فارغة**: الرسمُ يقرأُ العنوانَ
      | والنسبة، وتركيبةٌ من `{}` كانت تُخضِّرُ عدَّ الصفوفِ فوقَ رسمٍ لا يستطيعُ
      | أن يُرسَم.
      */
      return Promise.resolve({
        data: [
          { uuid: "e-1", course_title: "الفيزياء", progress_pct: 62 },
          { uuid: "e-2", course_title: "الكيمياء", progress_pct: 5 },
          { uuid: "e-3", course_title: "الأحياء", progress_pct: 30 },
          ...Array.from({ length: 12 }, (_, index) => ({
            uuid: `e-${index + 4}`,
            course_title: `كورس ${index + 4}`,
            progress_pct: 80,
          })),
        ],
        meta: { total: 37 },
      });
    }
    if (path === "/enrollments?status=completed") {
      return Promise.resolve({ data: [], meta: { total: 4 } });
    }
    if (path.startsWith("/certificates")) {
      return Promise.resolve({ data: [], meta: { total: 2 } });
    }
    if (path.startsWith("/schedule")) {
      return Promise.resolve({ data: [{ uuid: "b-1", status: "booked", session: SESSION }] });
    }
    if (path.startsWith("/billing/balance")) {
      return Promise.resolve({ data: [balanceRow()] });
    }
    if (path.startsWith("/notifications")) {
      return Promise.resolve({
        data: [
          {
            uuid: "n-1",
            type: "order_approved",
            type_label: "طلب معتمد",
            category: null,
            title: "تمّ اعتماد طلبك",
            body: "",
            action_url: null,
            subject: null,
            workspace: null,
            read_at: null,
            created_at: "2026-09-06T10:00:00+03:00",
          },
        ],
      });
    }
    if (path.startsWith("/orders")) {
      return Promise.resolve({
        data: [
          {
            uuid: "o-1",
            amount_minor: 5000,
            currency: "QAR",
            provider: "manual",
            status: "pending",
            rejection_reason: null,
            approved_at: null,
            kind: "credits",
            course_title: null,
            has_receipt: false,
            is_mine: true,
            receipt_url: null,
            review_sla_hours: 24,
            subscription: null,
            created_at: "2026-09-05T10:00:00+03:00",
          },
        ],
      });
    }

    return Promise.reject(new Error("403"));
  };
}

function asStudent(overrides: Record<string, unknown> = {}) {
  mockUser = { first_name: "سلمى", platform_role: "student", permissions: [] };
  get.mockImplementation(studentAnswer(overrides));
}

describe("DashboardPage · الطالب", () => {
  it("knocks on no door a student is refused", async () => {
    asStudent();

    render(<DashboardPage />);

    expect(await screen.findByText("حصصك القادمة")).toBeDefined();

    const paths: string[] = get.mock.calls.map((c) => c[0] as string);

    /*
    | ⚠️ الفهرسُ المحميُّ **وسوقُ الكورساتِ معاً**. الأوّلُ هو العطلُ المشحون:
    | `courses.view` صلاحيّةُ مساحةِ عملٍ والطالبُ عضوٌ في لا مساحة، فكلُّ
    | `can()` تحتَه false. والثاني كان الترقيعَ — عدٌّ لكتالوجِ المنصّةِ لا يقولُ
    | شيئاً عن دراسةِ هذا الطالبِ نفسِه، وقد حلَّ محلَّه ثلاثةُ أعدادٍ عنه هو.
    */
    expect(paths).not.toContain("/courses");
    expect(paths.some((p) => p.startsWith("/marketplace/courses"))).toBe(false);
    expect(paths).toContain("/enrollments?status=active");
    expect(paths.some((p) => p.startsWith("/schedule"))).toBe(true);
  });

  it("counts from the total, never from the rows of one page", async () => {
    asStudent();

    render(<DashboardPage />);

    // ٣٧ من `meta.total` فوقَ صفحةٍ من خمسةَ عشرَ صفّاً: الرقمُ الخاطئُ هنا ١٥.
    expect(await screen.findByText("٣٧")).toBeDefined();
    expect(screen.queryByText("١٥")).toBeNull();
  });

  it("keeps every other card when one source is refused", async () => {
    asStudent({ "/schedule": Promise.reject(new Error("500")) });

    render(<DashboardPage />);

    // البطاقةُ المعطوبةُ تعرضُ عطلَها **داخلَها** — بجملةٍ من `userMessage()`،
    // فلا خطأَ خامٌّ يصلُ القارئَ أبداً.
    expect(await screen.findByText(UNKNOWN_MESSAGE)).toBeDefined();
    expect(screen.getByText("حصصك القادمة")).toBeDefined();
    // …والبقيّةُ معروضة: هذا هو الفرقُ بينَ اليومَ و`Promise.all` الذي أسقطَ
    // اللوحةَ كلَّها من رفضٍ واحد.
    expect(await screen.findByText("تمّ اعتماد طلبك")).toBeDefined();
    expect(screen.getByText("رصيد حصصك")).toBeDefined();
    expect(screen.getByText("آخر الطلبات")).toBeDefined();
  });

  it("adds no single number across two courses", async () => {
    asStudent({
      // ⚠️ «مكتملة» تُبعَدُ عن الرقمِ ٤ عمداً: بطاقةُ الأعدادِ تطبعُه، فتوكيدٌ
      // على غيابِه كان سيسقطُ على رقمٍ لا علاقةَ له بالجمع.
      "/enrollments?status=completed": Promise.resolve({ data: [], meta: { total: 9 } }),
      "/billing/balance": Promise.resolve({
        data: [
          balanceRow({
            uuid: "b-1",
            course: { uuid: "c-1", title: "الرياضيات", teacher_name: "أ. منى" },
            remaining_credits: 10,
          }),
          balanceRow({
            uuid: "b-2",
            course: { uuid: "c-2", title: "الفيزياء", teacher_name: "أ. سالم" },
            remaining_credits: -6,
            is_withheld: true,
            credits_needed: 6,
          }),
        ],
      }),
    });

    render(<DashboardPage />);

    expect(await screen.findByText("الرياضيات")).toBeDefined();

    /*
    | ⚠️ `+10` و`−6` ليست `+4`. الحجبُ لكلِّ كورسٍ على حِدَة، فمجموعٌ واحدٌ يعِدُ
    | بسَعَةٍ في الكورسِ المحجوبِ ويُخفي الكورسَ الذي عليه أن يُدفَع.
    */
    expect(screen.queryByText("٤")).toBeNull();
    expect(screen.getByText(/محجوب/)).toBeDefined();
  });

  it("offers the room only when the server says the door is open", async () => {
    asStudent();

    render(<DashboardPage />);

    // بابٌ يفتحُ بعدَ ساعة: عدٌّ تنازليٌّ، ولا زرّ.
    expect(await screen.findByText("المتجهات")).toBeDefined();
    expect(screen.queryByText("دخول الغرفة")).toBeNull();
  });

  it("offers the room when the server already opened it", async () => {
    asStudent({
      "/schedule": Promise.resolve({
        data: [
          {
            uuid: "b-1",
            status: "booked",
            // ⚠️ جوابُ الخادمِ لا حسابُ المتصفّح، وهذا فرقٌ لا يراهُ إلّا اختبارُ
            // مكوِّن: الخلفيّةُ لا تعرفُ أنّ هذا الزرَّ موجودٌ أصلاً.
            session: {
              ...SESSION,
              join_open: true,
              seconds_until_join_open: 0,
              seconds_until_start: 120,
            },
          },
        ],
      }),
    });

    render(<DashboardPage />);

    expect(await screen.findByText("دخول الغرفة")).toBeDefined();
  });

  it("keeps the room shut once it has been closed, whatever the status says", async () => {
    asStudent({
      "/schedule": Promise.resolve({
        data: [
          {
            uuid: "b-1",
            status: "booked",
            // حصّةٌ ما زالت `live` وغرفتُها مغلقة: مدرّسٌ أنهى البثَّ مبكّراً.
            session: {
              ...SESSION,
              status: "live",
              status_label: "جارية",
              join_open: true,
              room_closed: true,
              seconds_until_join_open: null,
              seconds_until_start: 0,
            },
          },
        ],
      }),
    });

    render(<DashboardPage />);

    expect(await screen.findByText("انتهت")).toBeDefined();
    expect(screen.queryByText("دخول الغرفة")).toBeNull();
  });
});

/*
|------------------------------------------------------------------------------
| ٠٢٩ · US3 — وليُّ الأمر
|------------------------------------------------------------------------------
|
| ⚠️ الحالاتُ هنا تقيسُ **قائمةَ النداءات** كما تقيسُها حالةُ الطالبِ فوقَها،
| وللسببِ نفسِه: قاعدةٌ تُجيبُ كلَّ مسارٍ بنجاحٍ تُخرِجُ شاشةً خضراءَ لا تقولُ
| شيئاً عن أيِّ بابٍ طُرِق — وبابُ وليِّ الأمرِ إذنُ وِلايةٍ لا صلاحيّةُ مساحة.
*/

const ALL_PERMISSIONS = [
  { key: "schedule", label: "المواعيد والحصص" },
  { key: "attendance", label: "الحضور والغياب" },
  { key: "payments", label: "المدفوعات والمستحقّات" },
  { key: "results", label: "النتائج والدرجات" },
];

function relation(over: Record<string, unknown> = {}) {
  return {
    uuid: "r-1",
    relation_type: "parent",
    relation_type_label: "وليّ أمر",
    status: "active",
    status_label: "نشط",
    student_name: "كريم",
    student_uuid: "child-1",
    student_has_account: true,
    permissions: ALL_PERMISSIONS,
    revoked_at: null,
    created_at: null,
    ...over,
  };
}

function childSession(studentUuid: string) {
  return {
    uuid: `booking-${studentUuid}`,
    class_session: {
      uuid: `session-${studentUuid}`,
      title: "المتجهات",
      starts_at: "2026-09-08T09:00:00+00:00",
      ends_at: "2026-09-08T10:00:00+00:00",
      status: "scheduled",
      status_label: "مجدولة",
      room_closed: false,
      course: { uuid: "c-1", title: "الفيزياء" },
      teacher_name: "أ. منى",
    },
  };
}

function guardianAnswer(relations: unknown[]) {
  return (path: string) => {
    if (path.startsWith("/family/relations")) return Promise.resolve({ data: relations });

    const student = new URLSearchParams(path.split("?")[1] ?? "").get("student") ?? "";

    if (path.startsWith("/schedule/children")) {
      return Promise.resolve({ data: [childSession(student)] });
    }
    if (path.startsWith("/attendance/children/summary")) {
      return Promise.resolve({
        data: {
          window_days: 30,
          present: 5,
          late: 1,
          absent: 2,
          excused: 2,
          total: 10,
          rate_pct: 80,
        },
      });
    }
    if (path.startsWith("/billing/children/balance")) return Promise.resolve({ data: [] });
    if (path.startsWith("/report-cards")) return Promise.resolve({ data: [] });

    return Promise.reject(new Error("403"));
  };
}

function asGuardian(relations: unknown[]) {
  mockUser = { first_name: "أمّ كريم", platform_role: "parent" };
  get.mockImplementation(guardianAnswer(relations));
}

function calledPaths(): string[] {
  return get.mock.calls.map((c) => c[0] as string);
}

describe("DashboardPage · وليّ الأمر", () => {
  it("asks the guardian's own endpoints and none of the student's", async () => {
    asGuardian([relation()]);

    render(<DashboardPage />);

    expect(await screen.findByText("حصص كريم القادمة")).toBeDefined();

    await waitFor(() => {
      expect(calledPaths().some((p) => p.startsWith("/schedule/children?student=child-1"))).toBe(
        true,
      );
    });

    const paths = calledPaths();
    expect(paths.some((p) => p.startsWith("/attendance/children/summary?student=child-1"))).toBe(
      true,
    );
    expect(paths.some((p) => p.startsWith("/billing/children/balance?student=child-1"))).toBe(true);
    expect(paths.some((p) => p.startsWith("/report-cards?student=child-1"))).toBe(true);

    /*
    | ⚠️ وهذه هي التوكيدةُ التي تسقطُ لو لم يُوجِّهْ `page.tsx` أصلاً: اللوحةُ
    | القائمةُ تقرأُ `/enrollments` و`/certificates` عن **القارئ** — وهي عن
    | وليِّ أمرٍ ثلاثةُ أصفارٍ عن حسابٍ لا يدرس، تحتَ «أهلاً بعودتك».
    */
    expect(paths.some((p) => p.startsWith("/enrollments"))).toBe(false);
    expect(paths.some((p) => p.startsWith("/schedule/children"))).toBe(true);
  });

  it("omits a card whose permission was not granted, and never draws it empty", async () => {
    asGuardian([relation({ permissions: [{ key: "schedule", label: "المواعيد والحصص" }] })]);

    render(<DashboardPage />);

    expect(await screen.findByText("حصص كريم القادمة")).toBeDefined();

    // ⚠️ غائبةٌ لا فارغة: «توزيع حضور كريم» فوقَ فراغٍ جملةٌ عن ابنٍ لا يحضر، وسببُها
    // إذنٌ لم يُمنَحْ لا حصّةٌ لم تُحضَر.
    expect(screen.queryByText("توزيع حضور كريم")).toBeNull();
    expect(screen.queryByText("رصيد حصص كريم")).toBeNull();
    expect(screen.getAllByText(/غير ممنوح لك/).length).toBe(3);

    // ولا يُسأَلُ الخادمُ سؤالاً يعرفُ القارئُ أنّه سيُرفَض.
    expect(calledPaths().some((p) => p.startsWith("/attendance/children/summary"))).toBe(false);
  });

  it("keeps a name-only child out of the switcher", async () => {
    asGuardian([
      relation(),
      // ابنٌ بالاسمِ وحدَه: لا `student_uuid`، فلا سؤالَ يُطرَحُ عنه إطلاقاً.
      relation({ uuid: "r-2", student_name: "آلاء", student_uuid: undefined, student_has_account: false }),
      // ورابطٌ ملغًى: `childrenOf()` يُرشِّحُ النشِطَ وحدَه، فصفٌّ له هنا هو
      // ابنٌ كلُّ بطاقاتِه تُجيبُ ٤٠٣.
      relation({ uuid: "r-3", student_name: "بدر", student_uuid: "child-3", status: "revoked" }),
    ]);

    render(<DashboardPage />);

    expect(await screen.findByText("حصص كريم القادمة")).toBeDefined();

    // ابنٌ واحدٌ صالحٌ ⇒ لا مُبدِّلَ أصلاً.
    expect(screen.queryByLabelText("الابن المعروضة بياناته")).toBeNull();
    expect(calledPaths().some((p) => p.includes("child-3"))).toBe(false);
  });

  it("refetches every card of the child that was switched to", async () => {
    asGuardian([
      relation({ uuid: "r-1", student_name: "آدم", student_uuid: "child-1" }),
      relation({ uuid: "r-2", student_name: "بدر", student_uuid: "child-2" }),
    ]);

    render(<DashboardPage />);

    const picker = await screen.findByLabelText("الابن المعروضة بياناته");

    // ⚠️ `fireEvent` لا `userEvent`: الثاني ينتظرُ مؤقّتاتٍ حقيقيّةً بينَ خطواتِه،
    // وهي القاعدةُ التي كتبَها `ConfirmButton` في هذا المستودعِ من قبل.
    fireEvent.change(picker, { target: { value: "child-2" } });

    await waitFor(() => {
      expect(calledPaths().some((p) => p.startsWith("/schedule/children?student=child-2"))).toBe(
        true,
      );
    });

    // **كلُّ** بطاقاتِه، لا الجدولَ وحدَه: بطاقةٌ لا تُعيدُ الجلبَ تعرضُ أرقامَ
    // ابنٍ تحتَ اسمِ أخيه، وهي الحالةُ التي لا يراها أحدٌ لأنّها لا تُخطئ.
    const paths = calledPaths();
    expect(paths.some((p) => p.startsWith("/attendance/children/summary?student=child-2"))).toBe(
      true,
    );
    expect(paths.some((p) => p.startsWith("/billing/children/balance?student=child-2"))).toBe(true);
    expect(paths.some((p) => p.startsWith("/report-cards?student=child-2"))).toBe(true);
  });

  it("offers no way into the room from a child's timetable", async () => {
    asGuardian([relation()]);

    render(<DashboardPage />);

    expect(await screen.findByText("المتجهات")).toBeDefined();

    // ⚠️ وليُّ الأمرِ لا مقعدَ له: الخادمُ يرفضُ دخولَه، فزرٌّ هنا وعدٌ يُجيبُه
    // ٤٠٣. والمَورِدُ الضيِّقُ لا يُرسِلُ `join_open` أصلاً — هذا نصفُه الآخر.
    expect(screen.queryByText("دخول الغرفة")).toBeNull();
    expect(screen.queryByRole("button", { name: /دخول/ })).toBeNull();
  });

  it("sends a guardian with no linked child to the one screen that helps", async () => {
    asGuardian([relation({ student_uuid: undefined, student_has_account: false })]);

    render(<DashboardPage />);

    // لا أربعُ بطاقاتٍ فارغةٍ تصفُ عطلاً — سطرٌ واحدٌ وخطوةٌ واحدة.
    expect(await screen.findByText(/لا يوجد ابن مرتبط بحساب/)).toBeDefined();
    expect(screen.queryByText("حصص كريم القادمة")).toBeNull();
    expect(screen.getByText("إدارة المرتبطين").getAttribute("href")).toBe("/family");
  });
});

/*
|------------------------------------------------------------------------------
| ٠٢٩ · US2 — المدرّس والمساعد
|------------------------------------------------------------------------------
|
| ⚠️ قائمةُ النداءاتِ هي المقياس هنا كما هي فوق، وللسببِ الإضافيِّ الذي يخصُّ
| هذه الشريحةَ وحدَها: **الفرقُ بينَ المدرّسِ ومساعدِه ليس ما يُرسَمُ بل ما
| يُطلَب**. بطاقةٌ محجوبةٌ تُخفي نفسَها **ولا تُنادي** — سؤالٌ يعرفُ القارئُ أنّه
| سيُرفَضُ هو ٤٠٣ في سِجِلِّ الخادمِ مقابلَ لا شيءٍ على الشاشة.
|
| والحالاتُ الثلاثُ التي كانت هنا سقطَت مع `LegacyDashboard.tsx`: كانت تقيسُ
| جسدَ الشاشةِ القديمِ نفسَه — عدَّ الكتالوجِ من `meta.total` وسقوطَه على رفضٍ
| واحد — ولم يبقَ منه شيءٌ يُقاس. وما كانت تحرسُه انتقلَ إلى حالاتِ الطالبِ،
| وهي أوسعُ منها.
*/

function teacherAnswer(path: string) {
  if (path.startsWith("/class-sessions")) {
    // ⚠️ بلا `teacher_name`: تقويمُ المدرّسِ لا يُحمِّلُ `teacherProfile` مسبقاً
    // فالمفتاحُ غائبٌ عن حمولتِه أصلاً — وتركيبةٌ تحملُه تصفُ ردّاً لا يُرسِلُه
    // هذا المسار، وترسمُ اسمَ زميلٍ تحتَ «حصصي» في الاختبارِ وحدَه.
    return Promise.resolve({ data: [{ ...SESSION, teacher_name: undefined }] });
  }
  if (path.startsWith("/manage/grading/queue")) {
    return Promise.resolve({ data: [], meta: { total: 7 } });
  }
  if (path.startsWith("/manage/private-session-requests")) {
    return Promise.resolve({ data: [], meta: { total: 3 } });
  }
  if (path.startsWith("/manage/billing/students")) {
    return Promise.resolve({
      data: [
        // ⚠️ صفّانِ لشخصٍ واحدٍ محجوبٍ في مادّتَين: الحجبُ لكلِّ كورسٍ وحدَه،
        // فعدُّ الصفوفِ يُبلِّغُ المدرّسَ عن ضعفِ من عندَه.
        { student_uuid: "st-1", course_uuid: "c-1", is_withheld: true },
        { student_uuid: "st-1", course_uuid: "c-2", is_withheld: true },
        { student_uuid: "st-2", course_uuid: "c-1", is_withheld: false },
      ],
    });
  }
  if (path.startsWith("/notifications")) return Promise.resolve({ data: [] });
  /*
  | ⚠️ بلا غلافِ `data`: `profileApi.teacher()` يقرأُ الملفَّ من جذرِ الردِّ لا من
  | مفتاحٍ بداخلِه — وتركيبةٌ تُغلِّفُه تصفُ ردّاً لا يُرسِلُه هذا المسار، فتمرُّ
  | البطاقةُ في الاختبارِ وترسمُ «—» في المتصفِّح.
  */
  if (path.startsWith("/teacher/profile")) {
    return Promise.resolve({ faqs: [{ question: "س", answer: "ج" }] });
  }

  return Promise.reject(new Error("403"));
}

function asTeacher(user: Record<string, unknown>) {
  mockUser = { first_name: "محمود", platform_role: "teacher", ...user };
  get.mockImplementation(teacherAnswer);
}

/** مدرّسٌ كامل: يستضيفُ حصصاً ويحملُ الصلاحيّاتِ الثلاث. */
const HOST = {
  teacher_profile_uuid: "t-1",
  permissions: ["sessions.manage", "grading.perform", "billing.balance.view"],
};

describe("DashboardPage · المدرّس", () => {
  it("asks for its host's own calendar and for the three counts", async () => {
    asTeacher(HOST);

    render(<DashboardPage />);

    expect(await screen.findByText("حصصي القادمة")).toBeDefined();

    await waitFor(() => {
      expect(calledPaths().some((p) => p.startsWith("/class-sessions"))).toBe(true);
    });

    const paths = calledPaths();

    // ⚠️ `includes` لا مساواةً حرفيّة: ترتيبُ `URLSearchParams` يتبعُ ترتيبَ
    // مفاتيحِ الكائن، وتوكيدةٌ على العنوانِ كاملاً تنكسرُ عندَ إعادةِ ترتيبٍ
    // لا يُغيِّرُ شيئاً.
    expect(paths.some((p) => p.includes("teacher=t-1"))).toBe(true);
    expect(paths.some((p) => p.startsWith("/manage/grading/queue"))).toBe(true);
    expect(paths.some((p) => p.startsWith("/manage/private-session-requests"))).toBe(true);
    expect(paths.some((p) => p.startsWith("/manage/billing/students"))).toBe(true);

    /*
    | ⚠️ وهذه هي التوكيدةُ التي تسقطُ لو لم يُوجِّهْ `page.tsx`: اللوحةُ المحذوفةُ
    | كانت تقرأُ `/enrollments` و`/certificates` عن **القارئ** — وهي عن مدرّسٍ
    | صفرانِ عن حسابِه هو كطالبٍ لا يدرس، تحتَ «كورسات جارية ٠».
    */
    expect(paths.some((p) => p.startsWith("/enrollments"))).toBe(false);
    expect(screen.queryByText("كورسات جارية")).toBeNull();
  });

  it("counts people, not rows, among the withheld", async () => {
    asTeacher(HOST);

    render(<DashboardPage />);

    expect(await screen.findByText("طلاب محجوبون")).toBeDefined();

    /*
    | ⚠️ كلُّ رقمٍ داخلَ بطاقتِه، لا في الصفحةِ كلِّها: رسمُ الأسبوعِ يرسمُ عدداً
    | لكلِّ يومٍ فيتكرَّرُ «١» على الشاشة — وتوكيدةٌ عامّةٌ كانت ستُصيبُ عمودَ
    | يومٍ وتمرُّ خضراءَ فوقَ بطاقةٍ تعدُّ خطأً.
    */
    const card = (title: string) => within(screen.getByText(title).closest("section") as HTMLElement);

    // صفّانِ لطالبٍ واحدٍ ⇒ «١»، لا «٢».
    expect(card("طلاب محجوبون").getByText("١")).toBeDefined();
    // والرقمانِ الآخرانِ من `meta.total` لا من طولِ الصفحةِ الفارغة.
    expect(card("بانتظار التصحيح").getByText("٧")).toBeDefined();
    expect(card("طلبات الحصص الخاصة").getByText("٣")).toBeDefined();
  });

  it("hides every financial card from an assistant, and knocks on no door they lack", async () => {
    /*
    | مساعدٌ بالصلاحيّاتِ الافتراضيّة: يقرأُ الحصصَ ولا يُديرُها، ولا تصحيحَ ولا
    | رصيد. والمصفوفةُ في الخادمِ تُعطيه `sessions.view` وحدَها من هذه الأربع.
    */
    asTeacher({ teacher_profile_uuid: null, permissions: ["sessions.view", "courses.update"] });

    render(<DashboardPage />);

    expect(await screen.findByText("روابط سريعة")).toBeDefined();

    expect(screen.queryByText("طلاب محجوبون")).toBeNull();
    expect(screen.queryByText("بانتظار التصحيح")).toBeNull();
    expect(screen.queryByText("طلبات الحصص الخاصة")).toBeNull();

    // وما يملكُه يبقى: الإشعاراتُ والروابطُ السريعة.
    expect(screen.getByText("آخر الإشعارات")).toBeDefined();

    const paths = calledPaths();
    expect(paths.some((p) => p.startsWith("/manage/billing/students"))).toBe(false);
    expect(paths.some((p) => p.startsWith("/manage/grading/queue"))).toBe(false);
    expect(paths.some((p) => p.startsWith("/manage/private-session-requests"))).toBe(false);
  });

  it("drops the teacher filter and says so in the heading when nobody hosts", async () => {
    asTeacher({ teacher_profile_uuid: null, permissions: ["sessions.manage"] });

    render(<DashboardPage />);

    /*
    | ⚠️ العنوانُ والمُرشِّحُ يتحرّكانِ معاً، ولذلك الحالتانِ متجاورتان: قراءةٌ
    | بلا `teacher` تحتَ «حصصي» تعرضُ حصّةَ زميلٍ على أنّها حصّتُه، وقراءةٌ
    | بـ`teacher` تحتَ «حصصُ المساحة» تعرضُ جدولاً فارغاً لمن لا يستضيفُ شيئاً.
    */
    expect(await screen.findByText("حصص مكان العمل القادمة")).toBeDefined();
    expect(screen.queryByText("حصصي القادمة")).toBeNull();

    await waitFor(() => {
      expect(calledPaths().some((p) => p.startsWith("/class-sessions"))).toBe(true);
    });

    expect(calledPaths().some((p) => p.includes("teacher="))).toBe(false);
  });

  it("shows no timetable at all to someone who neither hosts nor manages", async () => {
    // لا عنوانَ ثالثاً مخترَعاً: بطاقةٌ لا مصدرَ لها لا تُعرَضُ أصلاً (`FR-015`).
    asTeacher({ teacher_profile_uuid: null, permissions: ["courses.update"] });

    render(<DashboardPage />);

    expect(await screen.findByText("روابط سريعة")).toBeDefined();

    expect(screen.queryByText("حصصي القادمة")).toBeNull();
    expect(screen.queryByText("حصص مكان العمل القادمة")).toBeNull();
    expect(calledPaths().some((p) => p.startsWith("/class-sessions"))).toBe(false);
  });
});

/*
|------------------------------------------------------------------------------
| ٠٢٩ · US4 — الرسوم
|------------------------------------------------------------------------------
|
| ⚠️ **«الرسمُ يقرأُ أرقامَ الجدولِ فوقَه» توكيدةٌ فارغةٌ إن قِيسَت بالتساوي.**
| ما دامَ الاثنانِ يقتسمانِ ردّاً واحداً فالتساوي صحيحٌ بالبناءِ ويمرُّ فوقَ أيِّ
| تنفيذٍ كان. المقياسُ الذي يعضُّ هو **عددُ النداءات**: مسارٌ واحدٌ نُودِيَ
| **مرّةً واحدة**. وطلبانِ لنفسِ المسارِ بعدَ لحظةٍ من بعضِهما يُجيبانِ إجابتَينِ
| مختلفتَينِ أحياناً — حصّةٌ حُجِزَت بينهما — فيقولُ الجدولُ خمساً ويرسمُ الرسمُ
| ستّاً بلا خطأٍ في أيِّ مكان.
|
| والنصفُ الثاني إتاحةٌ لا اتّساق: كلُّ قيمةٍ مرسومةٍ مقروءةٌ **نصّاً**، فالشريطُ
| والعمودُ `aria-hidden` وما يبقى للقارئِ هو الرقم.
*/

function timesCalled(prefix: string): number {
  return calledPaths().filter((path) => path.startsWith(prefix)).length;
}

describe("DashboardPage · الرسوم", () => {
  it("draws the student's courses from the one read the counter used", async () => {
    asStudent();

    render(<DashboardPage />);

    expect(await screen.findByText("تقدّمك في كورساتك")).toBeDefined();

    // ⚠️ **مرّةً واحدةً**: البطاقتانِ تقرآنِ المسارَ نفسَه في التمريرةِ نفسِها.
    await waitFor(() => expect(timesCalled("/enrollments?status=active")).toBe(1));

    // والأقلُّ إنجازاً أوّلاً: «الكيمياء» عند ٥٪ قبلَ «الأحياء» عند ٣٠٪.
    const bars = screen.getAllByRole("progressbar");
    expect(bars[0]?.getAttribute("aria-label")).toBe("الكيمياء");
    expect(bars[0]?.getAttribute("aria-valuenow")).toBe("5");

    // وكلُّ قيمةٍ مرسومةٍ مقروءةٌ نصّاً بجوارِها.
    expect(screen.getByText("٥٪")).toBeDefined();
    expect(screen.getByText("٣٠٪")).toBeDefined();
  });

  it("draws the teacher's week from the one read the timetable used", async () => {
    asTeacher(HOST);

    render(<DashboardPage />);

    expect(await screen.findByText("حصص الأسبوع القادم")).toBeDefined();

    await waitFor(() => expect(timesCalled("/class-sessions")).toBe(1));

    /*
    | سبعةُ أعمدةٍ دائماً — لا عمودٌ لكلِّ يومٍ فيه حصّة. ويومٌ خالٍ عمودٌ صفريٌّ
    | مكتوبٌ «٠»، لا فجوةٌ يقرؤُها المدرّسُ على أنّها خطأٌ في الرسم.
    */
    const chart = within(
      screen.getByText("حصص الأسبوع القادم").closest("section") as HTMLElement,
    );
    expect(chart.getAllByRole("listitem")).toHaveLength(7);
    expect(chart.getAllByText("٠").length).toBe(6);
    expect(chart.getAllByText("١").length).toBe(1);
  });

  it("draws seven zero columns AND says why, when the week is empty", async () => {
    mockUser = { first_name: "محمود", platform_role: "teacher", ...HOST };
    get.mockImplementation((path: string) =>
      path.startsWith("/class-sessions")
        ? Promise.resolve({ data: [] })
        : teacherAnswer(path),
    );

    render(<DashboardPage />);

    const chart = within(
      (await screen.findByText("حصص الأسبوع القادم")).closest("section") as HTMLElement,
    );

    /*
    | ⚠️ **الأعمدةُ والجملةُ معاً** (`FR-007` سيناريو ٣). رسمٌ فارغٌ صامتٌ يُقرَأُ
    | على أنّه عطلٌ في الشاشة، وجملةٌ بلا أعمدةٍ تحذفُ شكلَ الأسبوعِ الذي جاءَ
    | المدرّسُ ليراه. ولذلك لا تُستعمَلُ خاصّيّةُ `empty` هنا: هي تحلُّ **محلَّ**
    | المحتوى.
    */
    expect(chart.getAllByRole("listitem")).toHaveLength(7);
    expect(chart.getAllByText("٠")).toHaveLength(7);
    expect(chart.getByText(/لا حصص في السبعة أيام القادمة/)).toBeDefined();
    expect(chart.getByText("أنشئ حصّة").getAttribute("href")).toBe("/manage/sessions");
  });

  it("draws the child's attendance from the one read the summary used", async () => {
    asGuardian([relation()]);

    render(<DashboardPage />);

    expect(await screen.findByText("توزيع حضور كريم")).toBeDefined();

    await waitFor(() =>
      expect(timesCalled("/attendance/children/summary?student=child-1")).toBe(1),
    );

    /*
    | الأعدادُ الأربعةُ نصّاً في المفتاح، لا في عرضِ قطعةٍ ملوّنة: الشريطُ كلُّه
    | `aria-hidden`، ولونٌ وحدَه لا يقولُ شيئاً لمن لا يُفرِّقُ الأخضرَ من الأحمر.
    */
    const chart = within(
      screen.getByText("توزيع حضور كريم").closest("section") as HTMLElement,
    );
    expect(chart.getByText("حاضر")).toBeDefined();
    expect(chart.getByText("٥")).toBeDefined();
    expect(chart.getByText("متأخّر")).toBeDefined();
    expect(chart.getByText("بعذر")).toBeDefined();
  });
});

/*
| ٠٢٩ · طلبُ ٢٠٢٦-٠٩-٠٧: «قد يكون لديه أكثر من ابن أو وصيّ — تحديد اسمهم
| والتنقّل بينهم وإمكانيّة عمل مقارنة بينهم».
|
| ⚠️ والحالةُ الفارقةُ هي **الخليةُ بلا إذن**: جدولُ مقارنةٍ يكتبُ «٠٪» مكانَ
| «غير ممنوح» يتّهمُ ابناً لم يُسأَلْ عنه أحد.
*/
describe("DashboardPage · مقارنة الأبناء", () => {
  function twoChildren() {
    asGuardian([
      relation({ uuid: "r-1", student_name: "آدم", student_uuid: "child-1" }),
      relation({
        uuid: "r-2",
        student_name: "بدر",
        student_uuid: "child-2",
        // بدرٌ بإذنِ الجدولِ وحدَه: ثلاثُ خلايا من أربعٍ غيرُ ممنوحة.
        permissions: [{ key: "schedule", label: "المواعيد والحصص" }],
      }),
    ]);
  }

  it("names every child and never prints a number where a permission is missing", async () => {
    twoChildren();

    render(<DashboardPage />);

    const table = (await screen.findByText("مقارنة سريعة")).closest("section") as HTMLElement;

    await waitFor(() => {
      expect(within(table).getByText("آدم")).toBeDefined();
    });

    expect(within(table).getByText("بدر")).toBeDefined();

    // ثلاثُ خلايا لبدرٍ وحدَه — والحضورُ لآدمَ رقمٌ حقيقيّ.
    expect(within(table).getAllByText("غير ممنوح").length).toBe(3);
    expect(within(table).getByText("٨٠٪")).toBeDefined();
  });

  it("is not drawn for a guardian with one child", async () => {
    asGuardian([relation()]);

    render(<DashboardPage />);

    expect(await screen.findByText("حصص كريم القادمة")).toBeDefined();
    // «مقارنةٌ» بصفٍّ واحدٍ جدولٌ يصفُ نفسَه.
    expect(screen.queryByText("مقارنة سريعة")).toBeNull();
  });

  it("titles each card with the child on screen, never «ابنك»", async () => {
    // ⚠️ ووصيٌّ ليس أباً: «ابنك» خطأٌ في حقِّه حتّى بابنٍ واحد.
    twoChildren();

    render(<DashboardPage />);

    expect(await screen.findByText("حصص آدم القادمة")).toBeDefined();

    fireEvent.change(await screen.findByLabelText("الابن المعروضة بياناته"), {
      target: { value: "child-2" },
    });

    expect(await screen.findByText("حصص بدر القادمة")).toBeDefined();
    expect(screen.queryByText("حصص آدم القادمة")).toBeNull();
  });
});

/*
|------------------------------------------------------------------------------
| بطاقةُ الأسئلةِ الشائعةِ على لوحةِ المدرّس (طلبُ ٢٠٢٦-٠٩-٠٨)
|------------------------------------------------------------------------------
|
| ⚠️ حالتانِ لا واحدة، والثانيةُ هي التي تبيتُ. البطاقةُ تقرأُ `/teacher/profile`،
| وهو **٤٠٣ لحسابٍ بلا ملفٍّ عامّ** — مساعدُ مدرّسٍ جمهورُه `teacher` وليست له
| صفحة. فبطاقةٌ ترسمُ «تعذّر التحميل» عندَه لافتةُ عطلٍ على شاشةٍ تعملُ تماماً،
| وتوكيدةُ الحالةِ السعيدةِ وحدَها خضراءُ فوقَ ذلك.
*/
describe("DashboardPage · بطاقة الأسئلة الشائعة", () => {
  it("counts the teacher's own FAQs and points at the editor", async () => {
    asTeacher(HOST);

    render(<DashboardPage />);

    expect(await screen.findByText("الأسئلة الشائعة")).toBeDefined();

    await waitFor(() => {
      expect(calledPaths().some((p) => p.startsWith("/teacher/profile"))).toBe(true);
    });

    // ⚠️ الرابطُ يحملُ المرساةَ: المحرِّرُ قسمٌ داخلَ «ملفّي»، ورابطٌ إلى رأسِ
    // الصفحةِ يتركُ المدرّسَ يبحثُ عن الحقلِ الذي أرسلَته البطاقةُ إليه.
    const link = screen.getByRole("link", { name: "أضف أو عدّل" });

    expect(link.getAttribute("href")).toBe("/settings/profile#faqs");
  });

  it("draws nothing at all for an account with no public listing", async () => {
    asTeacher(HOST);
    get.mockImplementation((path: string) =>
      path.startsWith("/teacher/profile")
        ? Promise.reject(new Error("403"))
        : teacherAnswer(path),
    );

    render(<DashboardPage />);

    // انتظرْ حتّى تُرسَمَ اللوحةُ، ثمّ اسألْ عمّا يجبُ ألّا يكونَ فيها.
    expect(await screen.findByText("حصصي القادمة")).toBeDefined();

    await waitFor(() => {
      expect(calledPaths().some((p) => p.startsWith("/teacher/profile"))).toBe(true);
    });

    expect(screen.queryByText("الأسئلة الشائعة")).toBeNull();
  });
});
