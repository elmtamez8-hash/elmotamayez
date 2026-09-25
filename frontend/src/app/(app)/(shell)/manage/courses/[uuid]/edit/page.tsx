"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { COURSE_TYPES } from "@/lib/labels";
import type { Course } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { PageHeader } from "@/components/ui/PageHeader";
import { CheckIcon, CoursesIcon } from "@/components/icons";
import {
  CheckboxField,
  SelectField,
  TextField,
  TextareaField,
} from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

/*
  ما يُقال للمدرّس عن حالة فيديوه. «مرفوض» بلا سببٍ يُجيبه بلصقِ الرابطِ نفسِه،
  والسببُ يصل من المراجعة في سجلّ النشاط لا في هذا الحقل — فالجملةُ هنا تقول
  ماذا يفعل، لا ماذا حدث.
*/
const PROMO_HINT: Record<string, string> = {
  none: "الصق رابط فيديو من يوتيوب يشرح فيه درساً. يظهر في صفحة الكورس العامّة بعد المراجعة.",
  pending: "فيديوك بانتظار المراجعة. لن يظهر في الصفحة العامّة قبل اعتماده.",
  approved: "فيديوك معتمَد ويظهر في صفحة الكورس. لصقُ رابطٍ آخر يعيده إلى المراجعة.",
  rejected: "لم يُعتمَد الفيديو. الصق رابطاً آخر ليُراجَع من جديد.",
};

export default function EditCoursePage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);
  const router = useRouter();

  const [course, setCourse] = useState<Course | null>(null);
  const [form, setForm] = useState({
    title: "",
    description: "",
    currency: "QAR",
    is_sequential: true,
    subject: "",
    grade_level: "",
    course_type: "",
    promo_video_url: "",
  });
  /*
    ⚠️ THE EDIT SCREEN CARRIES IT OR THE BACKFILL IS UNCORRECTABLE. Every course
    that existed before the subject was required was stamped «عامّ» — a visible
    placeholder rather than a guessed subject — and this is the only screen where
    a teacher can put the right one in. Without the field the placeholder would be
    permanent, which is a worse state than the null it replaced.
  */
  const [subjects, setSubjects] = useState<{ uuid: string; label: string }[]>([]);
  /*
    ⚠️ HERE FOR THE SAME REASON THE SUBJECT IS. `courses.grade_level` had no
    writer at all until 2026-09-09, so 95 of 96 existing courses carry no stage —
    and this is the only screen where a teacher can put one in. Without the field
    the filter that reads it would be permanently one option wide.
  */
  const [stages, setStages] = useState<{ slug: string; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<Course>(`/courses/${uuid}`)
      .then((c) => {
        setCourse(c);
        setForm({
          title: c.title,
          description: c.description ?? "",
          currency: c.currency,
          is_sequential: c.is_sequential,
          subject: c.subject?.uuid ?? "",
          grade_level: c.grade_level ?? "",
          /*
            ⚠️ THIS SCREEN IS WHERE THE BACKFILL BECOMES CORRECTABLE. The column
            carried a DB default of `recorded` with no writer since 2026-08-01,
            and the migration that repairs it derives «جماعي» ONLY from a group
            row — an unambiguous fact — and deliberately leaves every other
            course alone rather than inventing a classification. So the courses
            it did not touch are put right here, by the person who knows.
          */
          course_type: c.course_type ?? "",
          /*
            ⚠️ SEEDED EMPTY EVEN WHEN A VIDEO EXISTS, and that is deliberate.
            The server stores the extracted ID and never the pasted link, so
            there is no URL to put back — and reconstructing one here would be a
            second place that knows the host. An empty field with the status
            beside it reads correctly: «there is one, paste again to replace it».
          */
          promo_video_url: "",
        });
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  useEffect(() => {
    api
      .get<{ data: { uuid: string; label: string }[] }>("/course-subjects")
      .then((response) => setSubjects(response.data ?? []))
      .catch(() => setSubjects([]));
  }, []);

  useEffect(() => {
    api
      .get<{ data: { slug: string; name: string }[] }>("/signup/grade-levels")
      .then((response) => setStages(response.data ?? []))
      .catch(() => setStages([]));
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError("");
    setFields({});

    try {

      /*
        `promo_video_url` is sent only when the teacher actually typed something:
        the key's PRESENCE is what tells the server to touch the video at all, so
        sending an empty string on every save would clear an approved video every
        time the title was edited.
      */
      const { promo_video_url: pastedUrl, ...withoutPromo } = form;
      const payload: Record<string, unknown> = { ...withoutPromo };
      if (pastedUrl.trim() !== "") payload.promo_video_url = pastedUrl.trim();

      await api.put(`/courses/${uuid}`, payload);
      router.push(`/manage/courses/${uuid}`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  /*
    إزالةُ الفيديو (٠١٨ · FR-012). حقلٌ فارغٌ لا يُرسَلُ في الحفظِ العاديّ — وإلّا
    مُحيَ فيديو معتمَدٌ في كلِّ مرّةٍ يُعدَّلُ فيها العنوان — فالإزالةُ فعلٌ صريحٌ
    بمفردِه. وبدونِه يبقى المسارُ موجوداً في الواجهةِ البرمجيّةِ ولا شاشةَ تصلُه،
    وهو ما يعنيه «كلُّ سطحٍ جديدٍ يحتاجُ رابطاً داخلاً إليه».
  */
  const removePromoVideo = async () => {
    setRemoving(true);
    setError("");
    try {
      await api.put(`/courses/${uuid}`, { promo_video_url: null });
      setForm((f) => ({ ...f, promo_video_url: "" }));
      load();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setRemoving(false);
    }
  };

  const publish = async () => {
    setPublishing(true);
    setError("");
    try {
      await api.post(`/courses/${uuid}/publish`);
      // Refetch rather than router.refresh(): this is a client component and
      // the status badge is read from state, not from a server render.
      load();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setPublishing(false);
    }
  };

  if (loading) return <RowsSkeleton count={4} />;
  if (failed || !course) return <ErrorState onRetry={load} />;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <PageHeader
        Icon={CoursesIcon}
        title="تعديل الكورس"
        actions={
          course.status === "draft" ? (
            <Button
              loading={publishing}
              loadingLabel="جارٍ النشر…"
              iconStart={<CheckIcon className="h-4 w-4" />}
              onClick={publish}
            >
              انشر الكورس
            </Button>
          ) : undefined
        }
      />

      <Card as="section">
        <form onSubmit={submit} className="space-y-4">
          {error && <Alert tone="danger" title={error} />}

          <TextField
            id="title"
            label="عنوان الكورس"
            value={form.title}
            onChange={(v) => setForm({ ...form, title: v })}
            error={fields.title}
            required
          />

          <TextareaField
            id="description"
            label="الوصف"
            value={form.description}
            onChange={(v) => setForm({ ...form, description: v })}
            error={fields.description}
          />

          {/* الفيديو الترويجي (٠١٨). الحالة تُقرأ من الخادم لأنّ المدرّس يستحقّ
              أن يعرف لماذا لا يظهر زرّه في صفحة الكورس العامّة. */}
          <TextField
            id="promo_video_url"
            label="رابط الفيديو الترويجي"
            value={form.promo_video_url}
            onChange={(v) => setForm({ ...form, promo_video_url: v })}
            error={fields.promo_video_url}
            hint={PROMO_HINT[course?.promo_video_status ?? "none"]}
          />

          {course?.promo_video_id != null && (
            <ConfirmButton
              variant="secondary"
              size="sm"
              loading={removing}
              confirmLabel="اضغط ثانيةً لإزالة الفيديو"
              onConfirm={removePromoVideo}
            >
              إزالة الفيديو الترويجي
            </ConfirmButton>
          )}

          {/* Above the price, because it decides where the course is found:
              the marketplace groups by it, and a student's homework and practice
              filters narrow by it. */}
          <SelectField
            id="subject"
            label="المادّة"
            value={form.subject}
            onChange={(v) => setForm({ ...form, subject: v })}
            placeholder="اختر المادّة"
            options={subjects.map((subject) => ({ value: subject.uuid, label: subject.label }))}
            error={fields.subject}
            required
          />

          <SelectField
            id="grade_level"
            label="المرحلة الدراسية"
            value={form.grade_level}
            onChange={(v) => setForm({ ...form, grade_level: v })}
            placeholder="بلا مرحلة محدّدة"
            options={stages.map((stage) => ({ value: stage.slug, label: stage.name }))}
            error={fields.grade_level}
            hint="تُستخدم لفلترة كورساتك ولربط سعر التسوية بالمرحلة."
          />

          <SelectField
            id="course_type"
            label="نوع الكورس"
            value={form.course_type}
            onChange={(v) => setForm({ ...form, course_type: v })}
            placeholder="اختر النوع"
            options={COURSE_TYPES}
            error={fields.course_type}
            hint="يقرّر أين يظهر الكورس في السوق وكيف يُصنَّف فيه."
            required
          />

          {/*
            ⛔ **الشاشةُ تقولُ للمدرّسِ إنّ إعلانَه يخالفُ جدولَه.**

            التبويبُ عندَ الطالبِ صارَ يمشي وراءَ الجدولِ لا وراءَ هذا الحقل،
            فَخطؤُه لم يعدْ يُخفي حصصاً — لكنّه ما زالَ يضعُ شارةَ «مسجّل» على
            كورسٍ يُدرَّسُ حيّاً في السوقِ الذي يشتري منه الناس. فيُقالُ هنا،
            حيثُ يُصلَّحُ في نقرة، بدلَ أن يُكتشَفَ من مشترٍ توقّعَ فيديوهات.

            ⚠️ **في اتّجاهٍ واحدٍ فقط.** «جماعي» بلا حصصٍ بعدُ هو أوّلُ يومٍ في
            حياةِ كلِّ كورسٍ جماعيّ، وتحذيرٌ هناكَ يُنذِرُ عن حالةٍ صحيحة.

            ويُقرَأُ من `course` — ما على الخادمِ — لا من `form`، وإلّا لاختفى
            التحذيرُ في اللحظةِ التي يفتحُ فيها المدرّسُ القائمةَ وقبلَ أن يحفظ.
          */}
          {course.has_sessions && course.course_type === "recorded" && (
            <Alert tone="warning" title="هذا الكورس مكتوب «مسجّل» وله حصص حيّة في الجدول">
              سيظهر في السوق بشارة «مسجّل». اختر «جماعي» أو «فردي» ليعرفه الطلاب
              على حقيقته.
            </Alert>
          )}

          {/*
            ⛔ NO PRICE FIELD (owner decision 2026-09-25): a course is sold through
            a plan and nothing else, so a one-off price here would price nothing a
            student can buy. The column stays; this screen no longer writes it, and
            an edit leaves an existing value untouched because the key is not sent.
          */}

          <CheckboxField
            id="is_sequential"
            label="تسلسل إجباري — لا يفتح الدرس التالي قبل إتمام السابق"
            checked={form.is_sequential}
            onChange={(v) => setForm({ ...form, is_sequential: v })}
          />

          <div className="flex flex-wrap gap-3">
            <Button type="submit" loading={saving} loadingLabel="جارٍ الحفظ…">
              احفظ التغييرات
            </Button>
            <Button variant="secondary" onClick={() => router.back()}>
              إلغاء
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
