"use client";

import { useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { Exam } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, TextField, TextareaField } from "@/components/ui/Field";

export default function NewExamPage() {
  const router = useRouter();
  const [form, setForm] = useState({
    title: "",
    description: "",
    duration_minutes: "60",
    passing_score: "60",
    max_attempts: "3",
  });
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

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

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h2 className="text-2xl font-bold text-ink">اختبار جديد</h2>

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
