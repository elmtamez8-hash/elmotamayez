import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ProfileSettingsPage from "./page";
import type { User } from "@/lib/types";

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

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
  fieldErrors: () => ({}),
}));

vi.mock("@/lib/profile", () => ({
  profileApi: {
    teacher: () => teacher(),
    saveTeacher: (body: unknown) => saveTeacher(body),
    saveStudent: (body: unknown) => saveStudent(body),
    savePhoto: vi.fn(),
    removePhoto: vi.fn(),
  },
}));

let currentUser: Partial<User> | null = null;

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: currentUser }),
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
  teaching_languages: ["ar"],
  subjects: ["physics"],
  grade_levels: ["secondary"],
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
      { slug: "physics", name_ar: "الفيزياء" },
      { slug: "maths", name_ar: "الرياضيات" },
    ],
  },
  "/signup/grade-levels": { data: [{ slug: "secondary", name_ar: "الثانوية" }] },
  "/signup/school-years": { data: [{ slug: "year-10", name_ar: "الصف العاشر" }] },
  "/marketplace/regions": { data: [{ slug: "doha", name_ar: "الدوحة" }] },
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
  });
});

describe("an account with neither profile", () => {
  it("says so instead of offering a form that would be refused", async () => {
    teacher.mockRejectedValue(Object.assign(new Error("forbidden"), { status: 403 }));

    render(<ProfileSettingsPage />);

    await waitFor(() => {
      expect(screen.getByText("لا بيانات إضافية لهذا الحساب")).toBeDefined();
    });

    expect(screen.queryByText("صورة الحساب")).toBeNull();
  });
});
