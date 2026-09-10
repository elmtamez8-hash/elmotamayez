"use client";

import { toast } from "sonner";
import { useCallback, useEffect, useState } from "react";

import { AccountPhotoCard } from "@/components/settings/AccountPhotoCard";
import {
  DAYS,
  WeeklyAvailabilityEditor,
  type Slot,
} from "@/components/marketplace/WeeklyAvailabilityEditor";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import {
  CONTROL,
  MultiSelectField,
  NumberField,
  SelectField,
  TextareaField,
  TextField,
} from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api, fieldErrors } from "@/lib/api";
import { crossesUtcMidnight, toLocalSlot, toUtcSlot } from "@/lib/availability";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { profileApi, type Faq, type TeacherProfile } from "@/lib/profile";
import { QuestionIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";
import { TEACHING_LANGUAGES } from "@/lib/teaching-languages";

type Option = { slug: string; name: string };
type SchoolYear = { slug: string; name: string };

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
    faqs: [] as Faq[],
    intro_video_url: "",
    years_experience: 0,
    headline: "",
    bio: "",
  });

  /*
   * ساعةُ الحائطِ عندَ المدرّس، لا UTC. التحويلُ عندَ التحميلِ وعندَ الحفظِ
   * وحدَهما — وموضعٌ ثالثٌ يحوّلُ هو الحالةُ التي أصلحها ٢٠٢٦-٠٩-٠٢.
   */
  const [slots, setSlots] = useState<Slot[]>([]);

  const [student, setStudent] = useState({ school_year_slug: "", region_slug: "" });

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
        faqs: mine.faqs,
        intro_video_url: mine.intro_video_url ?? "",
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

      /*
       * ⚠️ وأسبوعٌ فارغٌ يُبدَأُ بصفٍّ واحدٍ لا بلا شيء: أزرارُ «أضف» تشتقُّ
       * من الصفِّ الأخير، فقائمةٌ فارغةٌ تتركُ المدرّسَ بلا طريقٍ لإضافةِ أوّلِه.
       */
      const week = mine.availability.map((slot) =>
        toLocalSlot({
          day_of_week: slot.day_of_week,
          start_time: slot.start_time.slice(0, 5),
          end_time: slot.end_time.slice(0, 5),
        }),
      );

      setSlots(week.length > 0 ? week : [{ day_of_week: 0, start_time: "16:00", end_time: "18:00" }]);
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

  /*
  | ⚠️ A TOAST, NOT A BANNER AT THE TOP OF THE DOCUMENT — reported 2026-09-09.
  | This page is long: the availability editor sits near the bottom, and its
  | «لا يمكن أن تتداخل فترتان في اليوم نفسه» was rendered above the heading,
  | metres above the control that provoked it. The teacher pressed save, nothing
  | visible happened, and they concluded the button was broken. A toast is
  | `position: fixed`, so it is in the viewport wherever the page is scrolled —
  | which is the whole of the fix; the sentence itself is unchanged.
  |
  | ⚠️ AND THE 422 BRANCH TOASTS TOO. It used to set the field messages and show
  | nothing else, so a refusal about a field off the bottom of the screen was
  | silent in exactly the same way — the banner was never the only door onto this
  | defect. The toast says WHERE to look and the field still carries the reason;
  | putting the reason itself in the toast would be «انتهت جلستك» under a list of
  | subjects, which is why the two were separated in the first place.
  |
  | ⚠️ Errors are given ten seconds, not sonner's four. An Arabic sentence
  | explaining a clash needs longer to read than a confirmation does, and this
  | one is the only record of why the save did not happen.
  */
  const save = async (task: Promise<unknown>) => {
    setSaving(true);
    setFields({});

    try {
      await task;
      toast.success("حُفِظت بياناتك، وهي منشورة الآن.");
    } catch (err: unknown) {
      const found = fieldErrors(err);

      if (Object.keys(found).length > 0) {
        setFields(found);
        toast.error("لم يُحفظ التغيير", {
          description: "راجع الحقول المميّزة بالأحمر في النموذج.",
          duration: 10000,
        });
      } else {
        toast.error("لم يُحفظ التغيير", { description: userMessage(err), duration: 10000 });
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <RowsSkeleton />;

  const hasProfile = teacher !== null || user?.student_profile != null;

  return (
    <div className="mx-auto max-w-2xl space-y-8">
      <h2 className="text-2xl font-bold text-ink">ملفّي</h2>

      {/*
        ⚠️ **الصورةُ لكلِّ حساب، وحجبُها خلفَ «هل لك ملفّ؟» كانَ يُغلِقُ الشاشةَ
        في وجهِ وليِّ الأمر** (بلاغُ ٢٠٢٦-٠٩-٠٨). و`‎/me/photo` بابٌ على مستوى
        الحسابِ بلا صلاحيّةٍ ولا مُعامِلِ مسار — الخادمُ يقبلُه من كلِّ من دخل —
        فالمنعُ كانَ في الشاشةِ وحدَها: وليُّ أمرٍ يفتحُ «ملفّي» فيقرأُ «لا بيانات
        إضافية لهذا الحساب» ولا يجدُ حتّى صورتَه. وهو الآن **أوّلُ بندٍ في قائمةِ
        الحساب**، فالبابُ المغلقُ صارَ أوّلَ ما يُطرَق.

        ⚠️ والجملةُ البديلةُ تصفُ **ما ينقُصُ لا الشاشةَ كلَّها**: «لا ملفَّ
        عامّاً» جملةٌ صحيحةٌ عن وليِّ أمرٍ يستطيعُ مع ذلك تغييرَ صورتِه هنا.
      */}
      <AccountPhotoCard
        initialUrl={teacher?.photo_url ?? user?.photo_url ?? null}
        name={user?.name ?? "؟"}
      />

      {!hasProfile && (
        <EmptyState
          title="لا ملفّ عامّ لهذا الحساب"
          description="الملفّ العامّ لمن يدرّس أو يدرس على المنصّة. صورتك واسمك فوق، وبقيّة بياناتك في صفحة الإعدادات."
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
                    /*
                     * ⚠️ الصفوفُ الفارغةُ تُسقَطُ هنا لا على الخادم: زرُّ «أضف
                     * سؤالاً» يفتحُ صفّاً فارغاً، فمدرّسٌ ضغطَه ثمّ عدلَ عن الكتابةِ
                     * كانَ سيُجابُ ٤٢٢ عن حقلٍ لم يقصدْ ملأَه أصلاً.
                     */
                    faqs: form.faqs.filter(
                      (faq) => faq.question.trim() !== "" || faq.answer.trim() !== "",
                    ),
                    intro_video_url:
                      form.intro_video_url.trim() === "" ? null : form.intro_video_url.trim(),
                    years_experience: Number(form.years_experience),
                    headline: form.headline,
                    bio: form.bio,
                  })
                  .then(setTeacher),
              );
            }}
          >
            {/* ⚠️ THREE `MultiSelectField`s, NOT WALLS OF CHIPS. Nine subjects,
                four stages and three languages laid out as toggle chips is a
                screenful of controls that all look alike, and a teacher scrolls
                past their own answer looking for the next question. The kit's
                control shows the chosen ones as a sentence and keeps the list
                behind one press — and it is `MultiSelectField` rather than
                `<select multiple>` for the reason written on the component: a
                plain click in the native control REPLACES the selection. */}
            <MultiSelectField
              id="subjects"
              label="المواد التي تدرّسها"
              error={fields.subjects}
              required
              placeholder="اختر المواد"
              value={form.subjects}
              onChange={(subjects) => setForm({ ...form, subjects })}
              options={subjects.map((subject) => ({
                value: subject.slug,
                label: subject.name,
              }))}
            />

            <MultiSelectField
              id="grade_levels"
              label="المراحل التي تدرّس لها"
              error={fields.grade_levels}
              required
              placeholder="اختر المراحل"
              value={form.grade_levels}
              onChange={(grade_levels) => setForm({ ...form, grade_levels })}
              options={stages.map((stage) => ({ value: stage.slug, label: stage.name }))}
            />

            <MultiSelectField
              id="teaching_languages"
              label="لغات التدريس"
              error={fields.teaching_languages}
              required
              placeholder="اختر اللغات"
              value={form.teaching_languages}
              onChange={(teaching_languages) => setForm({ ...form, teaching_languages })}
              options={TEACHING_LANGUAGES.map((language) => ({
                value: language.value,
                label: language.label,
              }))}
            />

            <TextField
              id="headline"
              label="سطر تعريفي"
              value={form.headline}
              onChange={(value) => setForm({ ...form, headline: value })}
              error={fields.headline}
              hint="سطر واحد يظهر تحت اسمك، حتى ١٥٠ حرفاً."
              required
            />

            {/* `TextareaField` for the reason the selects below moved to
                `SelectField`: a hand-rolled control is a second spelling of the
                kit's own padding, border and focus ring, and it drifts from
                them at the first token anybody changes. */}
            <TextareaField
              id="bio"
              label="نبذة عنك"
              value={form.bio}
              onChange={(value) => setForm({ ...form, bio: value })}
              rows={6}
              error={fields.bio}
            />

            <TextareaField
              id="qualifications"
              label="الشهادات والمؤهلات"
              hint="شهادة في كل سطر."
              value={form.qualifications}
              onChange={(value) => setForm({ ...form, qualifications: value })}
              rows={4}
              error={fields.qualifications}
            />

            {/* ⚠️ حقلُ رابطٍ لا رفعُ ملفّ: لا مسارَ رفعٍ ولا قرارَ تخزينٍ ولا فاتورةَ
                ترميزٍ لدقيقةٍ واحدة، والمدرّسُ يملكُ الفيديو على يوتيوبَ سلفاً.
                والتسميةُ تجمعُ الوجهَين اللذَين طلبَهما المستخدِم — تعريفٌ أو حصّةٌ
                تجريبيّة — لأنّ كليهما رابطُ فيديو واحد. */}
            <TextField
              id="intro_video_url"
              label="فيديو تعريفي أو حصة تجريبية"
              value={form.intro_video_url}
              onChange={(value) => setForm({ ...form, intro_video_url: value })}
              error={fields.intro_video_url}
              hint="رابط من يوتيوب أو فيميو. يظهر أعلى تبويب «نبذة» في صفحتك العامة."
            />

            {/*
              ⚠️ **هنا يكتبُ المدرّسُ أسئلتَه الشائعة** (طلبُ ٢٠٢٦-٠٩-٠٨). والمرساةُ
              `#faqs` هي ما تقصدُه بطاقةُ اللوحة، فالرابطُ يهبطُ على القسمِ نفسِه لا
              على رأسِ صفحةٍ طويلة.

              وداخلَ النموذجِ نفسِه لا في نموذجٍ ثانٍ: `PUT /teacher/profile` يستبدلُ
              الملفَّ كاملاً في كلِّ حفظ، فنموذجٌ ثانٍ يُرسِلُ الأسئلةَ وحدَها كانَ
              سيمسحُ ما في الحقولِ فوقَه.
            */}
            <fieldset id="faqs" className="scroll-mt-24 rounded-xl border border-line p-4">
              <legend className="flex items-center gap-2 px-2 text-sm font-semibold text-ink">
                <QuestionIcon className="h-4 w-4 text-primary-ink" />
                الأسئلة الشائعة
              </legend>

              <p className="mb-4 text-sm text-ink-muted">
                ما يسأله الطالب أو ولي أمره قبل الاشتراك — مدة الحصة، الواجبات،
                طريقة التواصل. تظهر في تبويب «أسئلة شائعة» على صفحتك العامة.
              </p>

              {form.faqs.length === 0 && (
                <p className="mb-4 text-sm text-ink-muted">لم تضف أي سؤال بعد.</p>
              )}

              <div className="space-y-4">
                {form.faqs.map((faq, index) => (
                  <div
                    /* ⚠️ المفتاحُ هو الترتيبُ عمداً: الصفُّ لا معرِّفَ له، ونصُّ
                       السؤالِ يتغيّرُ عندَ كلِّ حرفٍ يُكتَب — فمفتاحٌ منه يُعيدُ
                       بناءَ الحقلِ ويفقدُ التركيزَ بعدَ كلِّ ضغطةِ مفتاح. */
                    key={index}
                    className="space-y-3 rounded-lg border border-line p-3"
                  >
                    <TextField
                      id={`faq-question-${index}`}
                      // ⚠️ أرقامٌ عربيّةٌ هنديّة: الواجهةُ عربيّةٌ كلُّها، ورقمٌ
                      // لاتينيٌّ في تسميةِ حقلٍ يقرؤُه القارئُ الآليُّ بلغةٍ أخرى.
                      label={`السؤال ${arabicNumber(index + 1)}`}
                      value={faq.question}
                      onChange={(value) =>
                        setForm({
                          ...form,
                          faqs: form.faqs.map((row, at) =>
                            at === index ? { ...row, question: value } : row,
                          ),
                        })
                      }
                      error={fields[`faqs.${index}.question`]}
                    />

                    <TextareaField
                      id={`faq-answer-${index}`}
                      label="الإجابة"
                      value={faq.answer}
                      onChange={(value) =>
                        setForm({
                          ...form,
                          faqs: form.faqs.map((row, at) =>
                            at === index ? { ...row, answer: value } : row,
                          ),
                        })
                      }
                      rows={3}
                      error={fields[`faqs.${index}.answer`]}
                    />

                    <Button
                      type="button"
                      variant="ghost"
                      onClick={() =>
                        setForm({ ...form, faqs: form.faqs.filter((_, at) => at !== index) })
                      }
                    >
                      احذف هذا السؤال
                    </Button>
                  </div>
                ))}
              </div>

              {/* ⚠️ `type="button"`: زرٌّ عارٍ داخلَ نموذجٍ افتراضُه `submit`، فأوّلُ
                  ضغطةٍ على «أضف» كانت ستحفظُ الملفَّ بدلَ أن تفتحَ صفّاً. */}
              {/* الهامشُ على الغلافِ لا على الزرّ: `Button` لا يأخذُ `className`
                  حرّاً — المظهرُ طقمٌ مغلقٌ من الأنماط. */}
              <div className="mt-4">
                <Button
                  type="button"
                  variant="secondary"
                  disabled={form.faqs.length >= 20}
                  onClick={() =>
                    setForm({ ...form, faqs: [...form.faqs, { question: "", answer: "" }] })
                  }
                >
                  أضف سؤالاً
                </Button>
              </div>
            </fieldset>

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

      {teacher !== null && (
        <Card as="section">
          <h3 className="mb-1 font-semibold text-ink">مواعيدي الأسبوعية</h3>
          <p className="mb-4 text-sm text-ink-muted">
            الساعات التي يُحجَز فيها عندك. منها تُولَّد حصص مجموعاتك، وعليها يُرفَض
            طلب الحصة الخاصة خارجها، وهي ما تعرضه صفحتك العامة. بتوقيتك أنت.
          </p>

          <form
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();

              /*
               * ⚠️ نافذةٌ تعبُرُ منتصفَ ليلِ UTC لا يمكنُ تخزينُها إطلاقاً — الصفُّ
               * يحملُ يوماً وساعتَين — والخادمُ يرفضُها بجملةٍ عن أوقاتٍ لم
               * يكتبْها المدرّس. فالسؤالُ يُطرحُ هنا لتُقالَ الجملةُ الصّحيحة.
               */
              const straddling = slots.findIndex((slot) => crossesUtcMidnight(slot));

              if (straddling !== -1) {
                setFields({
                  availability: `فترة ${DAYS[slots[straddling].day_of_week]} تعبر منتصف الليل بالتوقيت العالمي. قسّمها إلى فترتين.`,
                });

                return;
              }

              void save(profileApi.saveAvailability(slots.map((slot) => toUtcSlot(slot))));
            }}
          >
            <WeeklyAvailabilityEditor
              slots={slots}
              onChange={setSlots}
              controlClassName={`${CONTROL} border-line`}
              disabled={saving}
            />

            {fields.availability && (
              <p className="text-sm text-danger-ink">{fields.availability}</p>
            )}

            <Button type="submit" loading={saving} loadingLabel="جارٍ الحفظ…">
              احفظ مواعيدي
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
            {/*
              ⚠️ `SelectField`, NEVER `Field` WRAPPED ROUND A BARE `Select`.
              `Select` is the chevron and the icon lane and NOTHING ELSE — it
              takes its whole appearance from the `className` its caller passes,
              so mounting it without one produced a browser-default control:
              smaller than every other field on the platform, with no border and
              no background, and an option list the browser painted white while
              the page's own light text stayed on it. `SelectField` is the paired
              form that applies `CONTROL` and the error border, which is what
              every other screen in the product uses.
            */}
            <SelectField
              id="school_year_slug"
              label="الصف الدراسي"
              placeholder="اختر الصف"
              value={student.school_year_slug}
              onChange={(value) => setStudent({ ...student, school_year_slug: value })}
              options={years.map((year) => ({ value: year.slug, label: year.name }))}
              error={fields.school_year_slug}
              required
            />

            <SelectField
              id="region_slug"
              label="المنطقة"
              placeholder="اختر المنطقة"
              value={student.region_slug}
              onChange={(value) => setStudent({ ...student, region_slug: value })}
              options={regions.map((region) => ({ value: region.slug, label: region.name }))}
              error={fields.region_slug}
              required
            />

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
