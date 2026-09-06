"use client";

import { useCallback, useEffect, useState } from "react";

import { AccountPhotoCard } from "@/components/settings/AccountPhotoCard";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Field, NumberField, Select, TextField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api, fieldErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { profileApi, type TeacherProfile } from "@/lib/profile";
import { TEACHING_LANGUAGES } from "@/lib/teaching-languages";

type Option = { slug: string; name_ar: string };
type SchoolYear = { slug: string; name_ar: string };

/**
 * «ملفّي» — حيثُ يصفُ المدرّسُ نفسَه ويُصحِّحُ الطالبُ بياناتِه.
 *
 * ⚠️ لم تكنْ هذه الشاشةُ موجودةً إطلاقاً، بلَّغَ بذلكَ المستخدِمُ في ٢٠٢٦-٠٩-٠٦.
 * كلُّ ما تكتبُه الخطوةُ الثانيةُ من معالجِ الانضمام — المواد والمراحل واللغات
 * والشهادات والعنوان والوصف — كانَ يُكتَبُ **مرّةً واحدةً** ولا يُعدَّلُ بعدَها
 * إلّا من لوحةِ الإدارة؛ و`student_profiles` يُكتَبُ عندَ التسجيلِ ولا شيءَ بعدَه،
 * بينما طالبُ الصفِّ التاسعِ يصيرُ في العاشرِ بعدَ سنة.
 *
 * ⚠️ والدَّورُ يُقرأُ من الخادمِ لا من `platform_role`: الملفُّ هو ما يقولُ إن كانَ
 * ثَمَّ صفحةٌ عامّةٌ تُحرَّر، والدَّورُ يقولُ بماذا سجَّلَ صاحبُ الحسابِ نفسَه —
 * واشتقاقُ الجمهورِ في TypeScript هو عطبُ «تهجئتَينِ لسؤالٍ واحد» بعينِه.
 */
export default function ProfileSettingsPage() {
  const { user } = useAuth();

  const [teacher, setTeacher] = useState<TeacherProfile | null>(null);
  const [subjects, setSubjects] = useState<Option[]>([]);
  const [stages, setStages] = useState<Option[]>([]);
  const [years, setYears] = useState<SchoolYear[]>([]);
  const [regions, setRegions] = useState<Option[]>([]);
  const [loading, setLoading] = useState(true);

  const [form, setForm] = useState({
    subjects: [] as string[],
    grade_levels: [] as string[],
    teaching_languages: [] as string[],
    qualifications: "",
    years_experience: 0,
    headline: "",
    bio: "",
  });

  const [student, setStudent] = useState({ school_year_slug: "", region_slug: "" });

  const [saved, setSaved] = useState("");
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);

    /*
     * ⚠️ THE TEACHER READ IS ALLOWED TO 403 AND THE PAGE STAYS FINE. A student
     * opening this page gets a refusal here by design, and an error banner about
     * a teacher profile would be noise on a page that is working correctly for
     * them — the same swallow `PublicProfileUrlCard` documents.
     */
    const mine = await profileApi.teacher().catch(() => null);

    setTeacher(mine);

    if (mine !== null) {
      setForm({
        subjects: mine.subjects,
        grade_levels: mine.grade_levels,
        teaching_languages: mine.teaching_languages,
        qualifications: mine.qualifications.join("\n"),
        years_experience: mine.years_experience ?? 0,
        headline: mine.headline ?? "",
        bio: mine.bio ?? "",
      });

      /*
       * ⚠️ `‎/signup/*`, NOT `‎/marketplace/*`. The marketplace lists drop every
       * entry with no publicly listed teacher — right for a filter bar and a
       * CIRCULAR LOCK here: the only subject a teacher is missing from the list
       * is the one nobody teaches yet, which is exactly the one they are adding.
       */
      /*
       * ⚠️ `{ data }`, NEVER A BARE ARRAY. `request()` in `lib/api.ts` wraps a
       * top-level array into `{ data: [...] }` on purpose — the API disables
       * resource wrapping, and the pages expect the envelope. Typing the call as
       * `Option[]` compiles perfectly and then explodes at runtime on `.map`,
       * because a cast is not a check: found by opening the page, after a
       * component test whose own fake returned the shape I had assumed.
       */
      const [s, g] = await Promise.all([
        api.get<{ data: Option[] }>("/signup/subjects"),
        api.get<{ data: Option[] }>("/signup/grade-levels"),
      ]);

      setSubjects(s.data);
      setStages(g.data);
    }

    if (user?.student_profile != null) {
      setStudent({
        school_year_slug: user.student_profile.school_year_slug ?? "",
        region_slug: user.student_profile.region_slug ?? "",
      });

      const [y, r] = await Promise.all([
        api.get<{ data: SchoolYear[] }>("/signup/school-years"),
        api.get<{ data: Option[] }>("/marketplace/regions"),
      ]);

      setYears(y.data);
      setRegions(r.data);
    }

    setLoading(false);
  }, [user?.student_profile]);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = (key: "subjects" | "grade_levels" | "teaching_languages", value: string) =>
    setForm((prev) => ({
      ...prev,
      [key]: prev[key].includes(value)
        ? prev[key].filter((item) => item !== value)
        : [...prev[key], value],
    }));

  const save = async (task: Promise<unknown>) => {
    setSaving(true);
    setSaved("");
    setError("");
    setFields({});

    try {
      await task;
      setSaved("حُفِظت بياناتك، وهي منشورة الآن.");
    } catch (err: unknown) {
      // 422 under its own field; everything else in the banner. Mixing them puts
      // «انتهت جلستك» under a list of subjects.
      const found = fieldErrors(err);

      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <RowsSkeleton />;

  const hasProfile = teacher !== null || user?.student_profile != null;

  return (
    <div className="mx-auto max-w-2xl space-y-8">
      <h2 className="text-2xl font-bold text-ink">ملفّي</h2>

      {error && <Alert tone="danger" title="لم يُحفظ التغيير">{error}</Alert>}
      {saved && <Alert tone="success" title={saved} />}

      {hasProfile ? (
        <AccountPhotoCard
          initialUrl={teacher?.photo_url ?? user?.photo_url ?? null}
          name={user?.name ?? "؟"}
        />
      ) : (
        <EmptyState
          title="لا بيانات إضافية لهذا الحساب"
          description="هذه الصفحة لمن يملك ملف مدرّس أو ملف طالب. حسابك ليس واحداً منهما، وبياناته الأساسية في صفحة الإعدادات."
        />
      )}

      {teacher !== null && (
        <Card as="section">
          <h3 className="mb-1 font-semibold text-ink">ملفك العام</h3>
          <p className="mb-4 text-sm text-ink-muted">
            هذا ما يقرأه الطالب وولي أمره على صفحتك. التعديل يظهر فوراً.
          </p>

          <form
            className="space-y-5"
            onSubmit={(event) => {
              event.preventDefault();

              void save(
                profileApi
                  .saveTeacher({
                    subjects: form.subjects,
                    grade_levels: form.grade_levels,
                    teaching_languages: form.teaching_languages,
                    // سطرٌ لكلِّ شهادة: حقلٌ واحدٌ بفواصلَ يجعلُ شهادةً فيها فاصلةٌ
                    // شهادتَين.
                    qualifications: form.qualifications
                      .split("\n")
                      .map((line) => line.trim())
                      .filter((line) => line !== ""),
                    years_experience: Number(form.years_experience),
                    headline: form.headline,
                    bio: form.bio,
                  })
                  .then(setTeacher),
              );
            }}
          >
            <Field id="subjects" label="المواد التي تدرّسها" error={fields.subjects} required>
              <div className="flex flex-wrap gap-2">
                {subjects.map((subject) => (
                  <label
                    key={subject.slug}
                    className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm ${
                      form.subjects.includes(subject.slug)
                        ? "border-primary bg-primary-soft text-primary-ink"
                        : "border-line text-ink"
                    }`}
                  >
                    <input
                      type="checkbox"
                      className="sr-only"
                      checked={form.subjects.includes(subject.slug)}
                      onChange={() => toggle("subjects", subject.slug)}
                    />
                    {subject.name_ar}
                  </label>
                ))}
              </div>
            </Field>

            <Field
              id="grade_levels"
              label="المراحل التي تدرّس لها"
              error={fields.grade_levels}
              required
            >
              <div className="flex flex-wrap gap-2">
                {stages.map((stage) => (
                  <label
                    key={stage.slug}
                    className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm ${
                      form.grade_levels.includes(stage.slug)
                        ? "border-primary bg-primary-soft text-primary-ink"
                        : "border-line text-ink"
                    }`}
                  >
                    <input
                      type="checkbox"
                      className="sr-only"
                      checked={form.grade_levels.includes(stage.slug)}
                      onChange={() => toggle("grade_levels", stage.slug)}
                    />
                    {stage.name_ar}
                  </label>
                ))}
              </div>
            </Field>

            <Field
              id="teaching_languages"
              label="لغات التدريس"
              error={fields.teaching_languages}
              required
            >
              <div className="flex flex-wrap gap-2">
                {TEACHING_LANGUAGES.map((language) => (
                  <label
                    key={language.value}
                    className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm ${
                      form.teaching_languages.includes(language.value)
                        ? "border-primary bg-primary-soft text-primary-ink"
                        : "border-line text-ink"
                    }`}
                  >
                    <input
                      type="checkbox"
                      className="sr-only"
                      checked={form.teaching_languages.includes(language.value)}
                      onChange={() => toggle("teaching_languages", language.value)}
                    />
                    {language.label}
                  </label>
                ))}
              </div>
            </Field>

            <TextField
              id="headline"
              label="سطر تعريفي"
              value={form.headline}
              onChange={(value) => setForm({ ...form, headline: value })}
              error={fields.headline}
              hint="سطر واحد يظهر تحت اسمك، حتى ١٥٠ حرفاً."
              required
            />

            <Field id="bio" label="نبذة عنك" error={fields.bio}>
              <textarea
                id="bio"
                value={form.bio}
                onChange={(event) => setForm({ ...form, bio: event.target.value })}
                rows={6}
                maxLength={5000}
                className="w-full rounded-xl border border-line bg-surface-raised px-3 py-2 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              />
            </Field>

            <Field
              id="qualifications"
              label="الشهادات والمؤهلات"
              error={fields.qualifications}
              hint="شهادة في كل سطر."
            >
              <textarea
                id="qualifications"
                value={form.qualifications}
                onChange={(event) => setForm({ ...form, qualifications: event.target.value })}
                rows={4}
                className="w-full rounded-xl border border-line bg-surface-raised px-3 py-2 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              />
            </Field>

            {/* `NumberField`, never `TextField type="number"` — the union does
                not carry it, deliberately: one spelling per control, or the two
                drift screen by screen. */}
            <NumberField
              id="years_experience"
              label="سنوات الخبرة"
              value={String(form.years_experience)}
              onChange={(value) => setForm({ ...form, years_experience: Number(value) })}
              error={fields.years_experience}
              min={0}
              max={60}
              required
            />

            <Button type="submit" loading={saving} loadingLabel="جارٍ الحفظ…">
              احفظ ملفي
            </Button>
          </form>
        </Card>
      )}

      {user?.student_profile != null && (
        <Card as="section">
          <h3 className="mb-1 font-semibold text-ink">بياناتي الدراسية</h3>
          <p className="mb-4 text-sm text-ink-muted">
            صفّك ومنطقتك. مرحلتك تُحسب من صفّك، فلا تُكتب بجانبه.
          </p>

          <form
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              void save(profileApi.saveStudent(student));
            }}
          >
            <Field id="school_year_slug" label="الصف الدراسي" error={fields.school_year_slug} required>
              <Select
                id="school_year_slug"
                value={student.school_year_slug}
                onChange={(event) =>
                  setStudent({ ...student, school_year_slug: event.target.value })
                }
              >
                <option value="">اختر الصف</option>
                {years.map((year) => (
                  <option key={year.slug} value={year.slug}>
                    {year.name_ar}
                  </option>
                ))}
              </Select>
            </Field>

            <Field id="region_slug" label="المنطقة" error={fields.region_slug} required>
              <Select
                id="region_slug"
                value={student.region_slug}
                onChange={(event) => setStudent({ ...student, region_slug: event.target.value })}
              >
                <option value="">اختر المنطقة</option>
                {regions.map((region) => (
                  <option key={region.slug} value={region.slug}>
                    {region.name_ar}
                  </option>
                ))}
              </Select>
            </Field>

            {/*
              ⛔ تاريخُ الميلادِ ورقمُ وليِّ الأمرِ ليسا هنا عمداً: الأوّلُ يقودُ
              بوّابةَ موافقةِ وليِّ الأمرِ وكنسةَ بلوغِ الرشد، والثاني هو العنوانُ
              الذي تُطلَبُ عليه تلكَ الموافقة — فبابٌ يفتحُه القاصرُ لنفسِه
              يُبطِلُ البوّابةَ من داخلِها. تغييرُهما يمرُّ بالدعم.
            */}
            <p className="text-xs text-ink-muted">
              لتغيير تاريخ الميلاد أو رقم ولي الأمر، تواصل مع الدعم — يُبنى عليهما
              إذنُ ولي الأمر.
            </p>

            <Button type="submit" loading={saving} loadingLabel="جارٍ الحفظ…">
              احفظ بياناتي
            </Button>
          </form>
        </Card>
      )}
    </div>
  );
}
