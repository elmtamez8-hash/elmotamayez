import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ProfileSettingsPage from "./page";
import type { User } from "@/lib/types";
import { setStoredViewerTimeZone } from "@/lib/viewer-time-zone";

/*
| ⚠️ THERE WAS NO SCREEN AT ALL — reported 2026-09-06.
|
| Everything step two of the teacher wizard writes was writable ONCE and then
| only from `/admin`; `student_profiles` was written at registration and never
| again; and neither photo column had a writer anywhere in the tree.
|
| ⚠️ AND THE AUDIENCE IS THE SERVER'S ANSWER, NOT `platform_role`. Deriving it in
| TypeScript is the two-spellings defect this repository keeps paying for — so
| both directions are asserted: a teacher must not be shown the student form, and
| a student must not be shown the listing form.
*/

const get = vi.fn();
const teacher = vi.fn();
const saveTeacher = vi.fn();
const saveStudent = vi.fn();
const saveAvailability = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
  fieldErrors: () => ({}),
  /*
  | ⚠️ `ApiError` HAS TO BE ON THE MOCK, because `userMessage()` — imported from
  | `@/lib/errors`, which this file does NOT mock — asks `err instanceof
  | ApiError` on the refusal path. Left out, the failure branch dies inside the
  | error handler with «No ApiError export is defined on the mock», which reads
  | like a bug in the page rather than a hole in the fixture.
  */
  ApiError: class ApiError extends Error {},
}));

vi.mock("@/lib/profile", () => ({
  profileApi: {
    teacher: () => teacher(),
    saveTeacher: (body: unknown) => saveTeacher(body),
    saveStudent: (body: unknown) => saveStudent(body),
    saveAvailability: (slots: unknown, timezone: unknown) => saveAvailability(slots, timezone),
    savePhoto: vi.fn(),
    removePhoto: vi.fn(),
  },
}));

let currentUser: Partial<User> | null = null;

const refreshUser = vi.fn(() => Promise.resolve());

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: currentUser, refreshUser }),
}));

const toastError = vi.fn();
const toastSuccess = vi.fn();

vi.mock("sonner", () => ({
  toast: {
    error: (...args: unknown[]) => toastError(...args),
    success: (...args: unknown[]) => toastSuccess(...args),
  },
}));

const TEACHER_PROFILE = {
  slug: "khaled",
  is_publicly_listed: true,
  workspace_participates_in_marketplace: true,
  photo_url: null,
  headline: "مدرّس فيزياء",
  bio: "نبذة",
  years_experience: 7,
  qualifications: ["ماجستير", "دبلوم"],
  /*
  | ⚠️ الحقلانِ هنا لأنّ الخادمَ يُرسِلُهما — لا لأنّ حالةً تحتاجُهما. تجهيزةٌ
  | ناقصةٌ عن الحمولةِ الحقيقيّةِ هي بعينُ ما جعلَ `years.map` ينفجرُ حيّاً وكلُّ
  | حالةٍ خضراء، وهي ما أسقطَ هذه الحالاتِ الأربعَ يومَ صارَ للأسئلةِ عمود.
  */
  faqs: [{ question: "كم مدة الحصة؟", answer: "ستون دقيقة." }],
  intro_video_url: null,
  teaching_languages: ["ar"],
  subjects: ["physics"],
  grade_levels: ["secondary"],
  // ⚠️ `H:i:s` كما يرسلُها الخادم، لا `H:i`: العميلُ يقتطعُ الثواني، وتجهيزةٌ
  // ترسلُ ما افترضتُه لا ما يُرسَلُ فعلاً هي التي جعلتْ `years.map` ينفجرُ حيّاً.
  availability: [{ day_of_week: 1, start_time: "09:00:00", end_time: "11:00:00", timezone: "Asia/Qatar" }],
};

/*
| ⚠️ `{ data: [...] }` AND NOT A BARE ARRAY, BECAUSE THAT IS WHAT THE CLIENT
| ACTUALLY HANDS BACK. `request()` wraps a top-level array into an envelope, and
| the first version of this fixture returned the bare arrays the ENDPOINT sends —
| so every case passed while the real page crashed on `years.map is not a
| function` the moment it was opened. A fake that answers a shape of its own
| invention proves nothing about the call it stands in for.
*/
const CATALOGUES: Record<string, unknown> = {
  "/signup/subjects": {
    data: [
      { slug: "physics", name: "الفيزياء" },
      { slug: "maths", name: "الرياضيات" },
    ],
  },
  "/signup/grade-levels": { data: [{ slug: "secondary", name: "الثانوية" }] },
  "/signup/school-years": { data: [{ slug: "year-10", name: "الصف العاشر" }] },
  "/marketplace/regions": { data: [{ slug: "doha", name: "الدوحة" }] },
};

beforeEach(() => {
  vi.clearAllMocks();
  currentUser = { name: "خالد", photo_url: null, student_profile: null } as Partial<User>;
  get.mockImplementation((path: string) => Promise.resolve(CATALOGUES[path] ?? { data: [] }));
});

describe("a teacher's own listing", () => {
  beforeEach(() => {
    teacher.mockResolvedValue(TEACHER_PROFILE);
  });

  it("prefills from what the server holds and hides the student form", async () => {
    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("ملفك العام")).toBeDefined();
    });

    expect((screen.getByLabelText(/سطر تعريفي/) as HTMLInputElement).value).toBe("مدرّس فيزياء");
    // ⚠️ `‎/signup/*` and not `‎/marketplace/*`: the marketplace list drops every
    // subject with no listed teacher, which is a circular lock on the one subject
    // a teacher is about to add.
    expect(get).toHaveBeenCalledWith("/signup/subjects");
    expect(get).not.toHaveBeenCalledWith("/marketplace/subjects");
    expect(screen.queryByText("بياناتي الدراسية")).toBeNull();
  });

  it("sends one qualification per LINE, never a comma-split string", async () => {
    // A single field split on commas turns «بكالوريوس فيزياء، جامعة قطر» into
    // two qualifications — and the teacher cannot write the one they have.
    saveTeacher.mockResolvedValue(TEACHER_PROFILE);

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("ملفك العام")).toBeDefined();
    });

    fireEvent.change(screen.getByLabelText(/الشهادات والمؤهلات/), {
      target: { value: "بكالوريوس فيزياء، جامعة قطر\n\nدبلوم تربوي  " },
    });

    fireEvent.click(screen.getByRole("button", { name: "احفظ ملفي" }));

    await waitFor(() => {
      expect(saveTeacher).toHaveBeenCalled();
    });

    expect(saveTeacher.mock.calls[0][0].qualifications).toEqual([
      "بكالوريوس فيزياء، جامعة قطر",
      "دبلوم تربوي",
    ]);
  });

  /*
  | محرِّرُ الأسئلةِ الشائعة (طلبُ ٢٠٢٦-٠٩-٠٨).
  |
  | ⚠️ **الصفُّ الفارغُ هو الحالةُ التي تُهدِرُ حفظاً كاملاً.** «أضف سؤالاً» يفتحُ
  | صفّاً بحقلَينِ فارغَين، وكلاهما `required` على الخادم — فمدرّسٌ ضغطَ الزرَّ ثمّ
  | عدلَ عن الكتابةِ يُجابُ ٤٢٢ عن حقلٍ لم يقصدْ ملأَه، وتضيعُ كلُّ تعديلاتِه في
  | النموذجِ فوقَه. الترشيحُ في العميلِ هو ما يمنعُ ذلك.
  */
  it("saves the FAQ rows the teacher wrote and drops the row they left blank", async () => {
    saveTeacher.mockResolvedValue(TEACHER_PROFILE);

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("ملفك العام")).toBeDefined();
    });

    // الصفُّ الأوّلُ جاءَ من الخادم، فالسؤالُ الأوّلُ مملوءٌ سلفاً.
    expect((screen.getByLabelText(/السؤال ١/) as HTMLInputElement).value).toBe("كم مدة الحصة؟");

    fireEvent.click(screen.getByRole("button", { name: "أضف سؤالاً" }));
    fireEvent.change(screen.getByLabelText(/السؤال ٢/), {
      target: { value: "هل توجد حصة تجريبية؟" },
    });
    fireEvent.change(screen.getAllByLabelText(/الإجابة/)[1], {
      target: { value: "نعم، الأولى مجانية." },
    });

    // وثالثٌ يُفتَحُ ولا يُملَأ — وهو ما يجبُ ألّا يصلَ الخادمَ إطلاقاً.
    fireEvent.click(screen.getByRole("button", { name: "أضف سؤالاً" }));

    fireEvent.click(screen.getByRole("button", { name: "احفظ ملفي" }));

    await waitFor(() => {
      expect(saveTeacher).toHaveBeenCalled();
    });

    expect(saveTeacher.mock.calls[0][0].faqs).toEqual([
      { question: "كم مدة الحصة؟", answer: "ستون دقيقة." },
      { question: "هل توجد حصة تجريبية؟", answer: "نعم، الأولى مجانية." },
    ]);
  });

  it("sends null for an intro video left empty, never an empty string", async () => {
    // الخادمُ يقبلُ `nullable` ويرفضُ نصّاً لا يطابقُ المضيفَين، والسلسلةُ الفارغةُ
    // ليست «لا فيديو» في كلِّ حارس — فالعميلُ يقولُها بالكلمةِ التي يعنيها.
    saveTeacher.mockResolvedValue(TEACHER_PROFILE);

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("ملفك العام")).toBeDefined();
    });

    fireEvent.click(screen.getByRole("button", { name: "احفظ ملفي" }));

    await waitFor(() => {
      expect(saveTeacher).toHaveBeenCalled();
    });

    expect(saveTeacher.mock.calls[0][0].intro_video_url).toBeNull();
  });
});

describe("the teacher's weekly availability", () => {
  /*
  | ⚠️ THE SECOND COLUMN IN TWO DAYS WITH READERS AND NO WRITER.
  | `availability_slots` was written once, at application submission, and read
  | ever since by the session generator, the private-session guard and the public
  | profile. A teacher whose week changed had no screen and no route.
  |
  | ⚠️ AND THE ROW IS WALL-CLOCK TIME ON A NAMED CLOCK (2026-09-25). The viewer's
  | zone is pinned here — it is the account's stored zone in the product — so the
  | assertions name hours without depending on the machine running them.
  */
  beforeEach(() => {
    teacher.mockResolvedValue(TEACHER_PROFILE);
    setStoredViewerTimeZone("Asia/Qatar");
  });

  afterEach(() => {
    setStoredViewerTimeZone(null);
  });

  it("sends back exactly what the server gave, when nothing was touched", async () => {
    saveAvailability.mockResolvedValue(TEACHER_PROFILE);

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("مواعيدي الأسبوعية")).toBeDefined();
    });

    fireEvent.click(screen.getByRole("button", { name: "احفظ مواعيدي" }));

    await waitFor(() => {
      expect(saveAvailability).toHaveBeenCalled();
    });

    // The seconds are gone (the column sends `H:i:s`, the field takes `H:i`), the
    // hour is the one typed, and the clock it is on is named beside it.
    expect(saveAvailability.mock.calls[0][0]).toEqual([
      { day_of_week: 1, start_time: "09:00", end_time: "11:00" },
    ]);
    expect(saveAvailability.mock.calls[0][1]).toBe("Asia/Qatar");
  });

  it("shows a week saved on another clock on this teacher's own, and saves it on theirs", async () => {
    // Saved from Doha (UTC+3), read in Cairo in winter (UTC+2): an hour earlier.
    setStoredViewerTimeZone("Africa/Cairo");
    vi.useFakeTimers({ toFake: ["Date"], now: new Date("2026-11-10T12:00:00Z") });
    saveAvailability.mockResolvedValue(TEACHER_PROFILE);

    try {
      render(<ProfileSettingsPage />);

      await waitFor(() => {
        expect(screen.getByText("مواعيدي الأسبوعية")).toBeDefined();
      });

      fireEvent.click(screen.getByRole("button", { name: "احفظ مواعيدي" }));

      await waitFor(() => {
        expect(saveAvailability).toHaveBeenCalled();
      });
    } finally {
      vi.useRealTimers();
    }

    expect(saveAvailability.mock.calls[0][0]).toEqual([
      { day_of_week: 1, start_time: "08:00", end_time: "10:00" },
    ]);
    expect(saveAvailability.mock.calls[0][1]).toBe("Africa/Cairo");
  });

  it("adds a period and removes one without leaving the week empty", async () => {
    saveAvailability.mockResolvedValue(TEACHER_PROFILE);

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("مواعيدي الأسبوعية")).toBeDefined();
    });

    // One row only: the server refuses an empty week, so a delete button on the
    // last row would be a control whose answer is always a refusal.
    /* ⚠️ الاسمُ كاملاً لا `/حذف/`: الصفحةُ فيها الآنَ «احذف هذا السؤال» في محرِّرِ
       الأسئلةِ الشائعة، فمُطابِقٌ جزئيٌّ يسألُ عن زرِّ الأسبوعِ ويجدُ زرَّ سؤال. */
    expect(screen.queryByRole("button", { name: "حذف" })).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "إضافة يوم" }));
    fireEvent.click(screen.getByRole("button", { name: "احفظ مواعيدي" }));

    await waitFor(() => {
      expect(saveAvailability).toHaveBeenCalled();
    });

    expect(saveAvailability.mock.calls[0][0]).toHaveLength(2);
  });

  it("refuses a cleared time instead of saving it as midnight", async () => {
    const { container } = render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("مواعيدي الأسبوعية")).toBeDefined();
    });

    const [start] = Array.from(container.querySelectorAll('input[type="time"]'));
    fireEvent.change(start, { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "احفظ مواعيدي" }));

    expect(await screen.findByText("أكمل وقتَي البداية والنهاية في كلّ فترة قبل الحفظ.")).toBeDefined();
    expect(saveAvailability).not.toHaveBeenCalled();
  });
});

describe("a student's own data", () => {
  beforeEach(() => {
    // The teacher read is REFUSED for them, by design, and the page stays fine.
    teacher.mockRejectedValue(Object.assign(new Error("forbidden"), { status: 403 }));

    currentUser = {
      name: "سارة",
      photo_url: null,
      student_profile: {
        grade_level_slug: "secondary",
        school_year_slug: "year-10",
        school_year_name: "الصف العاشر",
        region_slug: "doha",
        registered_by_parent: false,
      },
    } as Partial<User>;
  });

  it("offers the year and the region, and never the listing form", async () => {
    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("بياناتي الدراسية")).toBeDefined();
    });

    expect((screen.getByLabelText(/الصف الدراسي/) as HTMLSelectElement).value).toBe("year-10");
    expect((screen.getByLabelText(/المنطقة/) as HTMLSelectElement).value).toBe("doha");
    expect(screen.queryByText("ملفك العام")).toBeNull();
  });

  it("saves the year and the region together", async () => {
    saveStudent.mockResolvedValue({});

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("بياناتي الدراسية")).toBeDefined();
    });

    fireEvent.click(screen.getByRole("button", { name: "احفظ بياناتي" }));

    await waitFor(() => {
      expect(saveStudent).toHaveBeenCalledWith({
        school_year_slug: "year-10",
        region_slug: "doha",
      });
    });

    // The account in memory is read back, or the form refills from the old year.
    await waitFor(() => {
      expect(refreshUser).toHaveBeenCalled();
    });
  });
});

describe("telling the teacher whether it saved", () => {
  /*
  | ⚠️ IT WAS AN `<Alert>` AT THE TOP OF THE DOCUMENT, AND THIS PAGE IS LONG —
  | reported 2026-09-09. The availability editor sits near the bottom, so its
  | «لا يمكن أن تتداخل فترتان في اليوم نفسه» rendered metres above the control
  | that produced it: the teacher pressed save, saw nothing, and read that as a
  | broken button. A toast is `position: fixed`, so it is in the VIEWPORT
  | wherever the page is scrolled.
  |
  | ⚠️ AND THE 422 BRANCH IS THE HALF THAT WOULD BE MISSED. It used to set the
  | field messages and show nothing else at all — a refusal about a field below
  | the fold was silent by exactly the same mechanism, so a fix that toasted
  | only the general error would have left the reported defect standing on the
  | commoner path.
  */
  beforeEach(() => {
    currentUser = { platform_role: "teacher" } as Partial<User>;
    get.mockImplementation((path: string) => Promise.resolve(CATALOGUES[path] ?? { data: [] }));
    teacher.mockResolvedValue(TEACHER_PROFILE);
  });

  it("toasts the reason when the server refuses", async () => {
    saveTeacher.mockRejectedValue(
      Object.assign(new Error("clash"), { status: 422, message: "لا يمكن أن تتداخل فترتان في اليوم نفسه." }),
    );

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("ملفك العام")).toBeDefined();
    });

    fireEvent.click(screen.getByRole("button", { name: "احفظ ملفي" }));

    await waitFor(() => {
      expect(toastError).toHaveBeenCalled();
    });

    expect(toastError.mock.calls[0][0]).toBe("لم يُحفظ التغيير");
    // No banner left behind: two places saying it is two places to keep in step.
    expect(screen.queryByText("لم يُحفظ التغيير")).toBeNull();
  });

  it("toasts the confirmation too", async () => {
    saveTeacher.mockResolvedValue(TEACHER_PROFILE);

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("ملفك العام")).toBeDefined();
    });

    fireEvent.click(screen.getByRole("button", { name: "احفظ ملفي" }));

    await waitFor(() => {
      expect(toastSuccess).toHaveBeenCalledWith("حُفِظت بياناتك، وهي منشورة الآن.");
    });
  });
});

describe("an account with neither profile", () => {
  it("still owns its photo, and is told only what it lacks", async () => {
    /*
    | ⚠️ **هذه الحالةُ كانت تُرسِّخُ العطب**: كانت تؤكِّدُ أنّ بطاقةَ الصورةِ
    | **غائبة**، فوليُّ الأمرِ يفتحُ «ملفّي» — وهو أوّلُ بندٍ في قائمةِ حسابِه —
    | فلا يجدُ شيئاً يفعلُه (بلاغُ ٢٠٢٦-٠٩-٠٨). و`‎/me/photo` بابٌ على مستوى
    | الحسابِ بلا صلاحيّةٍ: الخادمُ كانَ يقبلُ منه، والشاشةُ وحدَها تمنعُه.
    */
    teacher.mockRejectedValue(Object.assign(new Error("forbidden"), { status: 403 }));

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("لا ملفّ عامّ لهذا الحساب")).toBeDefined();
    });

    expect(screen.getByText("صورة الحساب")).toBeDefined();
    // ولا نموذجَ ملفٍّ عامّ: البابُ الذي يُرفَضُ فعلاً يبقى مُغلَقاً.
    expect(screen.queryByText("ملفك العام")).toBeNull();
  });
});
