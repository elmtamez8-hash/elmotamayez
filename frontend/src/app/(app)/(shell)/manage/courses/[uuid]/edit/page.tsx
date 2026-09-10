"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { fromMinorMoney, toMinorMoney } from "@/lib/labels";
import type { Course } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import {
  CheckboxField,
  NumberField,
  SelectField,
  TextField,
  TextareaField,
} from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

const CURRENCIES = [
  { value: "QAR", label: "ريال قطري" },
  { value: "SAR", label: "ريال سعودي" },
  { value: "AED", label: "درهم إماراتي" },
  { value: "EGP", label: "جنيه مصري" },
  { value: "USD", label: "دولار أمريكي" },
];

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
    price: "0",
    currency: "QAR",
    is_sequential: true,
    subject: "",
    grade_level: "",
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
          price: fromMinorMoney(c.price_minor ?? 0),
          currency: c.currency,
          is_sequential: c.is_sequential,
          subject: c.subject?.uuid ?? "",
          grade_level: c.grade_level ?? "",
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
      const { price, ...rest } = form;

      /*
        `promo_video_url` is sent only when the teacher actually typed something:
        the key's PRESENCE is what tells the server to touch the video at all, so
        sending an empty string on every save would clear an approved video every
        time the title was edited.
      */
      const { promo_video_url: pastedUrl, ...withoutPromo } = rest;
      const payload: Record<string, unknown> = {
        ...withoutPromo,
        price_minor: toMinorMoney(price),
      };
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
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold text-ink">تعديل الكورس</h2>
        {course.status === "draft" && (
          <Button loading={publishing} loadingLabel="جارٍ النشر…" onClick={publish}>
            انشر الكورس
          </Button>
        )}
      </div>

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

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <NumberField
              id="price"
              label="السعر"
              value={form.price}
              onChange={(v) => setForm({ ...form, price: v })}
              error={fields.price_minor}
              min={0}
              step={0.01}
              hint="صفر يعني كورساً مجانياً."
            />
            <SelectField
              id="currency"
              label="العملة"
              value={form.currency}
              onChange={(v) => setForm({ ...form, currency: v })}
              options={CURRENCIES}
              error={fields.currency}
            />
          </div>

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
