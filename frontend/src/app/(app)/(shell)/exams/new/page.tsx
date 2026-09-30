"use client";

import { useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import type { Course, Exam } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { ExamIcon } from "@/components/icons";
import { NumberField, SelectField, TextField, TextareaField } from "@/components/ui/Field";

export default function NewExamPage() {
  const router = useRouter();
  const { user } = useAuth();
  /*
    ⛔ An assistant confined to some courses may not set a paper «for all my
    students» — the server refuses it (`ExamPolicy::placeInCourse()`). This page
    posted no course at all, so a confined assistant could not create an exam
    (2026-09-30). For them the course is required and «كل طلابي» is not offered;
    everybody else keeps the course-less paper, which reaches every student.
  */
  const confined = user?.is_confined_assistant === true;
  const [courses, setCourses] = useState<Course[]>([]);
  const [form, setForm] = useState({
    title: "",
    description: "",
    course: "",
    duration_minutes: "60",
    passing_score: "60",
    max_attempts: "3",
  });
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  // Already narrowed to a confined assistant's own courses on the server. A
  // failure leaves the list empty, which still offers «كل طلابي» to a teacher.
  useEffect(() => {
    api
      .get<{ data: Course[] }>("/courses?per_page=200")
      .then((response) => setCourses(response.data ?? []))
      .catch(() => setCourses([]));
  }, []);

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      const exam = await api.post<Exam>("/exams", {
        title: form.title,
        description: form.description,
        // The course as a uuid — the server resolves it. Empty is «كل طلابي».
        course: form.course === "" ? null : form.course,
        // The inputs hold strings so they can be cleared; the API wants numbers.
        duration_minutes: parseInt(form.duration_minutes, 10) || 60,
        passing_score: parseInt(form.passing_score, 10) || 60,
        max_attempts: parseInt(form.max_attempts, 10) || 3,
        status: "draft",
      });
      router.push(`/exams/${exam.uuid}/manage`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const courseOptions = courses.map((course) => ({ value: course.uuid, label: course.title }));

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <PageHeader Icon={ExamIcon} title="اختبار جديد" />

      <Card as="section">
        <form onSubmit={submit} className="space-y-4">
          {error && <Alert tone="danger" title={error} />}

          <TextField
            id="title"
            label="عنوان الاختبار"
            value={form.title}
            onChange={set("title")}
            error={fields.title}
            required
          />

          <TextareaField
            id="description"
            label="الوصف"
            value={form.description}
            onChange={set("description")}
            error={fields.description}
            rows={3}
          />

          {confined ? (
            <SelectField
              id="course"
              label="الكورس"
              value={form.course}
              onChange={set("course")}
              placeholder="اختر الكورس"
              options={courseOptions}
              error={fields.course}
              hint="تضع الاختبار في كورس من كورساتك."
              required
            />
          ) : (
            <SelectField
              id="course"
              label="الكورس"
              value={form.course}
              onChange={set("course")}
              options={[{ value: "", label: "كل طلابي" }, ...courseOptions]}
              error={fields.course}
              hint="بلا كورس يصل الاختبار إلى كل طلابك."
            />
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <NumberField
              id="duration_minutes"
              label="المدة بالدقائق"
              value={form.duration_minutes}
              onChange={set("duration_minutes")}
              error={fields.duration_minutes}
              min={1}
            />
            <NumberField
              id="passing_score"
              label="درجة النجاح ٪"
              value={form.passing_score}
              onChange={set("passing_score")}
              error={fields.passing_score}
              min={0}
              max={100}
            />
            <NumberField
              id="max_attempts"
              label="أقصى عدد محاولات"
              value={form.max_attempts}
              onChange={set("max_attempts")}
              error={fields.max_attempts}
              min={1}
            />
          </div>

          <div className="flex flex-wrap gap-3">
            <Button type="submit" loading={loading} loadingLabel="جارٍ الإنشاء…">
              أنشئ الاختبار
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
