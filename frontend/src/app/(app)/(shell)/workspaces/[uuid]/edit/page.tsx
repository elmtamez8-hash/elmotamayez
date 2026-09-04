"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { Workspace } from "@/lib/types";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

export default function EditWorkspacePage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);
  const router = useRouter();

  const [form, setForm] = useState({ name: "", slug: "" });
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Workspace[] }>("/workspaces")
      .then((res) => {
        const ws = res.data?.find((w) => w.uuid === uuid);
        if (!ws) throw new Error("not a member");
        setForm({ name: ws.name, slug: ws.slug ?? "" });
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
      await api.patch(`/workspaces/${uuid}`, form);
      router.push("/workspaces");
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <RowsSkeleton count={3} />;
  if (failed) return <ErrorState onRetry={load} />;

  return (
    <div className="mx-auto max-w-md space-y-6">
      <h2 className="text-2xl font-bold text-ink">إعدادات مكان العمل</h2>

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

          <TextField
            id="slug"
            label="المُعرِّف في الرابط"
            value={form.slug}
            onChange={(v) => setForm({ ...form, slug: v })}
            error={fields.slug}
            hint="حروف لاتينية وأرقام وشرطات فقط — يظهر في عنوان الصفحة."
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
