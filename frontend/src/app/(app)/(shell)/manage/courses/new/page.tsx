"use client";

import { useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { COURSE_TYPES } from "@/lib/labels";
import { CURRENCY } from "@/lib/platform";
import { useRouter } from "next/navigation";
import type { Course } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { CourseVisibilityField } from "@/components/courses/CourseVisibilityField";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { CoursesIcon } from "@/components/icons";
import {
  CheckboxField,
  SelectField,
  TextField,
  TextareaField,
} from "@/components/ui/Field";

export default function CreateCoursePage() {
  const router = useRouter();

  /*
    ⚠️ REQUIRED SINCE THE DAY `subject_id` WAS FOUND NULL ON EVERY COURSE ON THE
    PLATFORM. The column arrived with 007's pricing migration and was fillable
    from that day, so it read as finished — and no request, Action, seeder or
    screen ever wrote it. Everything that groups by subject was grouping nothing.
  */
  const [subjects, setSubjects] = useState<{ uuid: string; label: string }[]>([]);
  /*
    ⚠️ THE STAGE, AND IT HAD NO WRITER AT ALL UNTIL NOW — `subject_id`'s history
    one column along. `courses.grade_level` has been fillable since 006 and is
    read by the settlement-rate key and the course leaderboard, and no request,
    form, Action or seeder ever assigned it: NULL on 95 of 96 rows. Optional
    rather than required — making it mandatory is a product decision nobody has
    taken, and it would refuse every future edit of the 95.
  */
  const [stages, setStages] = useState<{ slug: string; name: string }[]>([]);
  const [form, setForm] = useState({
    title: "",
    description: "",
    currency: CURRENCY,
    is_sequential: true,
    is_free_enrollment: false,
    subject: "",
    grade_level: "",
    /*
      ⚠️ EMPTY, NOT PRE-PICKED. `courses.course_type` has carried a DB default of
      `recorded` since 2026-08-01 with nothing anywhere writing it, so every
      course a teacher made declared itself recorded about a choice nobody took —
      and the student's course page drops its «الحصص» tab on exactly that value.
      A default selected here would be that same unmade decision, moved into the
      browser; the server refuses an empty one.
    */
    course_type: "",
    // عامٌّ افتراضاً — قرارُ المالك 2026-09-26.
    visibility: "public" as "public" | "private",
  });
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

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
    setError("");
    setFields({});
    setLoading(true);

    try {
      const course = await api.post<Course>("/courses", form);
      router.push(`/manage/courses/${course.uuid}`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <PageHeader
        Icon={CoursesIcon}
        title="كورس جديد"
        description="املأ البيانات التالية لإنشاء الكورس."
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
            hint="اشرح في سطرين ماذا سيتعلّم الطالب."
          />

          {/*
            ⚠️ ABOVE THE PRICE, BECAUSE IT DECIDES WHERE THE COURSE IS FOUND. The
            subject is what the marketplace groups by and what a student's
            homework and practice filters narrow by — a course filed under
            nothing is a course that appears in none of them.
          */}
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

          {/*
            ⚠️ THE ONE FIELD WITH NO DEFAULT, AND THE HINT SAYS WHAT IT DECIDES.
            «جماعي» and «مسجّل» are the pair a teacher picks between wrongly, and
            what separates them is whether the course has live sessions at all —
            which is exactly what the student's course page reads this for.
          */}
          <SelectField
            id="course_type"
            label="نوع الكورس"
            value={form.course_type}
            onChange={(v) => setForm({ ...form, course_type: v })}
            placeholder="اختر النوع"
            options={COURSE_TYPES}
            error={fields.course_type}
            hint="يقرّر أين يظهر الكورس في السوق، وهل تظهر للطالب حصصه ومواعيدها."
            required
          />

          {/*
            ⛔ NO PRICE FIELD (owner decision 2026-09-25): a course is sold through
            a plan and nothing else, so a one-off price here would price nothing a
            student can buy. The column stays; this screen no longer writes it, and
            an edit leaves an existing value untouched because the key is not sent.
          */}

          {/*
            ⛔ «مجاني» is the teacher's decision and nothing else (owner decision
            2026-09-25). Unticked, the course is entered through a plan only; with
            no plan yet it shows «لم يفتح المدرّس الاشتراك بعد» — never «free».
          */}
          <CourseVisibilityField
            value={form.visibility}
            onChange={(visibility) => setForm({ ...form, visibility })}
            error={fields.visibility}
          />

          <CheckboxField
            id="is_free_enrollment"
            label="كورس مجاني — يسجّل فيه أي طالب بلا دفع ولا باقة"
            checked={form.is_free_enrollment}
            onChange={(v) => setForm({ ...form, is_free_enrollment: v })}
          />

          <CheckboxField
            id="is_sequential"
            label="تسلسل إجباري — لا يفتح الدرس التالي قبل إتمام السابق"
            checked={form.is_sequential}
            onChange={(v) => setForm({ ...form, is_sequential: v })}
          />

          <div className="flex flex-wrap gap-3">
            <Button type="submit" loading={loading} loadingLabel="جارٍ الإنشاء…">
              أنشئ الكورس
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
