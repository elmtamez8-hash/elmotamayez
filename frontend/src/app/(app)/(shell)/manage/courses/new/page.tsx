"use client";

import { useState } from "react";
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
  const [form, setForm] = useState({
    title: "",
    description: "",
    // A string, not a number: an empty numeric input yields "", and coercing it
    // to 0 on every keystroke made the field impossible to clear.
    price: "0",
    currency: CURRENCY,
    is_sequential: true,
  });
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

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
