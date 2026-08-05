"use client";

import { useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { Workspace } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField, SelectField } from "@/components/ui/Field";

const TYPES = [
  { value: "academy", label: "أكاديمية" },
  { value: "school", label: "مدرسة" },
  { value: "university", label: "جامعة" },
  { value: "company", label: "شركة" },
];

export default function NewWorkspacePage() {
  const router = useRouter();
  const [form, setForm] = useState({ name: "", type: "academy", slug: "" });
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      const ws = await api.post<Workspace>("/workspaces", form);
      await api.post(`/workspaces/${ws.uuid}/switch`);
      router.push("/dashboard");
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="mx-auto max-w-md space-y-6">
      <h2 className="text-2xl font-bold text-ink">مساحة عمل جديدة</h2>

      <Card as="section">
        <form onSubmit={submit} className="space-y-4">
          {error && <Alert tone="danger" title={error} />}

          <TextField
            id="name"
            label="الاسم"
            value={form.name}
            onChange={(v) => setForm({ ...form, name: v })}
            error={fields.name}
            required
          />

          <SelectField
            id="type"
            label="النوع"
            value={form.type}
            onChange={(v) => setForm({ ...form, type: v })}
            options={TYPES}
            error={fields.type}
          />

          <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الإنشاء…">
            أنشئ مساحة العمل
          </Button>
        </form>
      </Card>
    </div>
  );
}
