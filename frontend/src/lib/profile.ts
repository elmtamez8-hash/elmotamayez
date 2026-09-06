import { api } from "@/lib/api";
import type { User } from "@/lib/types";

/**
 * ما يصفُ به المدرّسُ نفسَه، ومصدرُه `GET /teacher/profile`.
 *
 * ⚠️ الحقولُ تُقرأُ من الخادمِ ولا تُشتقُّ من `platform_role`: الدَّورُ يقولُ بماذا
 * سجَّلَ صاحبُ الحسابِ نفسَه، والملفُّ يقولُ هل ثَمَّ صفحةٌ عامّةٌ تُحرَّر. وحسابٌ
 * بلا ملفٍّ يُجابُ ٤٠٣، وهو ما تبتلعُه الشاشةُ لترسمَ لا شيء.
 */
export type TeacherProfile = {
  slug: string | null;
  is_publicly_listed: boolean;
  workspace_participates_in_marketplace: boolean;
  photo_url: string | null;
  headline: string | null;
  bio: string | null;
  years_experience: number | null;
  qualifications: string[];
  teaching_languages: string[];
  subjects: string[];
  grade_levels: string[];
};

export type TeacherProfileInput = {
  subjects: string[];
  grade_levels: string[];
  teaching_languages: string[];
  qualifications: string[];
  years_experience: number;
  headline: string;
  bio: string;
};

export const profileApi = {
  teacher: () => api.get<TeacherProfile>("/teacher/profile"),

  saveTeacher: (body: TeacherProfileInput) =>
    api.put<TeacherProfile>("/teacher/profile", body),

  saveStudent: (body: { school_year_slug: string; region_slug: string }) =>
    api.patch<User>("/me/student-profile", body),

  /**
   * ⚠️ بابٌ واحدٌ للدَّورَين، والعمودُ الذي يُكتَبُ يتبعُ الملفَّ لا الطلب. ولا
   * يُرسَلُ اسمُ عمود: الخادمُ يعرفُ أيَّ ملفٍّ لصاحبِ الجلسة، وعميلٌ يُسمّي عموداً
   * هو عميلٌ يستطيعُ أن يُخطئَ في تسميتِه.
   */
  savePhoto: (file: File) => {
    const form = new FormData();

    form.append("photo", file);

    return api.upload<{ photo_url: string | null }>("/me/photo", form);
  },

  removePhoto: () => api.delete<{ photo_url: string | null }>("/me/photo"),
};
