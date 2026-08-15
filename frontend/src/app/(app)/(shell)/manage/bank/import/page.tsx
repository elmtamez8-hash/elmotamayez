"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { bank, importStatusLabel, type DuplicatePolicy, type ImportReport } from "@/lib/bank";

/**
 * Filling the bank from a file.
 *
 * ⚠️ THE DUPLICATE POLICY IS CHOSEN HERE, BEFORE THE UPLOAD, AND NEVER ASKED
 * ABOUT AGAIN. The import runs off the request; by the time it meets its first
 * duplicate this tab is closed. A job that stops to ask a question is a job that
 * hangs for ever.
 */
export default function ImportPage() {
  const router = useRouter();
  const fileInput = useRef<HTMLInputElement>(null);

  const [policy, setPolicy] = useState<DuplicatePolicy>("skip");
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [imports, setImports] = useState<ImportReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = () => {
    setLoading(true);
    setFailed(false);

    bank
      .imports()
      .then((response) => setImports(response.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const upload = async () => {
    if (file === null) return;

    setUploading(true);
    setError("");
    setErrors({});

    try {
      const started = await bank.startImport(file, policy);
      router.push(`/manage/bank/import/${started.data.uuid}`);
    } catch (err: unknown) {
      setErrors(fieldErrors(err));
      setError(userMessage(err));
      setUploading(false);
    }
  };

  const columns: Column<ImportReport>[] = [
    {
      key: "filename",
      header: "الملف",
      render: (row) => (
        <Link href={`/manage/bank/import/${row.uuid}`} className="text-primary hover:underline">
          {row.filename}
        </Link>
      ),
    },
    {
      key: "status",
      header: "الحالة",
      render: (row) => (
        <Badge
          tone={row.status === "done" ? "success" : row.status === "failed" ? "danger" : "info"}
        >
          {importStatusLabel(row.status)}
        </Badge>
      ),
    },
    { key: "imported", header: "أُضيف", numeric: true, render: (row) => row.imported_count },
    { key: "skipped", header: "تُخطّي", numeric: true, render: (row) => row.skipped_count },
    { key: "failed", header: "تعذّر", numeric: true, render: (row) => row.failed_count },
  ];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">استيراد أسئلة</h1>
        <p className="text-sm text-ink-muted">
          صفٌّ معطوب لا يُسقط الملف: تُستورد بقيّة الأسئلة، ويُسمّى كلّ صفٍّ لم يُضَف برقم سطره
          وسببه.
        </p>
      </header>

      {error !== "" && <Alert tone="danger" title="تعذّر الرفع">{error}</Alert>}

      <Card>
        <div className="space-y-4">
          {/* ⚠️ SAID IN WORDS, NOT ENFORCED SILENTLY. An .xlsx refused by a file
              picker with no reason is a teacher who tries three times and gives
              up; XLSX is a zip of XML and needs a library nobody has installed,
              so the honest answer is the sentence rather than a rejection. */}
          <Alert tone="info" title="الصيغة المدعومة">
            الصيغة المدعومة الآن هي <bdi>CSV</bdi> — و<bdi>XLSX</bdi> غير مدعوم بعد. احفظ ملفك من
            إكسل بصيغة <bdi>CSV UTF-8</bdi>.
          </Alert>

          <div>
            <label htmlFor="file" className="mb-1 block text-sm font-medium text-ink">
              الملف
            </label>
            <input
              id="file"
              ref={fileInput}
              type="file"
              accept=".csv,text/csv"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="block w-full rounded-xl border border-line bg-surface p-2 text-sm text-ink file:me-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-1.5 file:text-primary-ink"
            />
            {errors.file !== undefined && (
              <p className="mt-1 text-sm text-danger">{errors.file}</p>
            )}
          </div>

          <SelectField
            id="duplicate_policy"
            label="لو وُجد سؤالٌ بالنصّ نفسه"
            value={policy}
            onChange={(value) => setPolicy(value as DuplicatePolicy)}
            required
            error={errors.duplicate_policy}
            options={[
              { value: "skip", label: "تخطَّه ولا تُضف نسخة" },
              { value: "create", label: "أضفه نسخةً جديدة" },
            ]}
          />

          <p className="text-sm text-ink-muted">
            الأعمدة المطلوبة: <bdi>content</bdi> · <bdi>concept</bdi> · <bdi>difficulty</bdi> ·{" "}
            <bdi>bloom_level</bdi>. والاختيارية: <bdi>type</bdi> · <bdi>points</bdi> ·{" "}
            <bdi>explanation</bdi> · <bdi>options</bdi> · <bdi>correct</bdi>. الخيارات تُفصَل
            بـ<bdi>|</bdi>، و<bdi>correct</bdi> يحمل ترتيب الإجابة الصحيحة لا نصَّها.
          </p>

          <Button onClick={upload} disabled={file === null || uploading}>
            {uploading ? "جارٍ الرفع…" : "ابدأ الاستيراد"}
          </Button>
        </div>
      </Card>

      <section className="space-y-3">
        <h2 className="text-base font-semibold text-ink">عمليات سابقة</h2>
        <Table
          columns={columns}
          rows={imports}
          rowKey={(row) => row.uuid}
          caption="عمليات استيراد الأسئلة السابقة ونتائجها"
          state={loading ? "loading" : failed ? "error" : imports.length === 0 ? "empty" : "ready"}
          emptyTitle="لا عمليات استيراد بعد"
          emptyDescription="ارفع ملفاً وسيظهر تقريره هنا."
          onRetry={load}
        />
      </section>
    </div>
  );
}
