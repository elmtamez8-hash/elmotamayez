"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { Course } from "@/lib/types";
import { useRouter } from "next/navigation";
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
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

const CURRENCIES = [
  { value: "QAR", label: "ريال قطري" },
  { value: "SAR", label: "ريال سعودي" },
  { value: "AED", label: "درهم إماراتي" },
  { value: "EGP", label: "جنيه مصري" },
  { value: "USD", label: "دولار أمريكي" },
];

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
  });
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState(false);
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
          price: String(c.price ?? 0),
          currency: c.currency,
          is_sequential: c.is_sequential,
        });
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError("");
    setFields({});

    try {
      await api.put(`/courses/${uuid}`, { ...form, price: parseFloat(form.price) || 0 });
      router.push(`/manage/courses/${uuid}`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setSaving(false);
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

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <NumberField
              id="price"
              label="السعر"
              value={form.price}
              onChange={(v) => setForm({ ...form, price: v })}
              error={fields.price}
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
