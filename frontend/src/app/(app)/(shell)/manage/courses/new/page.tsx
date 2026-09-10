"use client";

import { useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { toMinorMoney } from "@/lib/labels";
import { CURRENCY } from "@/lib/platform";
import { useRouter } from "next/navigation";
import type { Course } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import {
  CheckboxField,
  NumberField,
  SelectField,
  TextField,
  TextareaField,
} from "@/components/ui/Field";

const CURRENCIES = [
  { value: "QAR", label: "ريال قطري" },
  { value: "SAR", label: "ريال سعودي" },
  { value: "AED", label: "درهم إماراتي" },
  { value: "EGP", label: "جنيه مصري" },
  { value: "USD", label: "دولار أمريكي" },
];

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
    // A string, not a number: an empty numeric input yields "", and coercing it
    // to 0 on every keystroke made the field impossible to clear.
    price: "0",
    currency: CURRENCY,
    is_sequential: true,
    subject: "",
    grade_level: "",
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
      // The field is riyals, the API takes fils. `price` itself is dropped
      // rather than sent alongside — a payload carrying both is one rename away
      // from the wrong one winning.
      const { price, ...rest } = form;

      const course = await api.post<Course>("/courses", {
        ...rest,
        price_minor: toMinorMoney(price),
      });
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
      <div>
        <h2 className="text-2xl font-bold text-ink">كورس جديد</h2>
        <p className="text-ink-muted">املأ البيانات التالية لإنشاء الكورس.</p>
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
