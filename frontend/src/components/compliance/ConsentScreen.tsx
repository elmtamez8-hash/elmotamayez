"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { compliance, type DataCategory, type DataProcessor } from "@/lib/compliance";

/**
 * What we collect, why, for how long, and who else sees it (FR-004 · SC-003).
 *
 * ⚠️ THE REQUIRED/OPTIONAL SPLIT IS THE WHOLE SCREEN. A single undifferentiated
 * list asks for consent to things that cannot be refused as though they could —
 * and the reverse, hiding the required ones, asks for consent to less than is
 * actually collected. Both are the same lie in opposite directions.
 *
 * ⚠️ AND APPEARING IN A CLASS RECORDING IS AMONG THE REQUIRED ONES, in words. It
 * is the one category a parent is most likely to be surprised by, so it is stated
 * as «صوتاً وصورةً» rather than as a technical noun — that exact sentence is what
 * `ConsentScreen.test.tsx` asserts, because the wording IS the requirement.
 */
export function ConsentScreen({
  studentUuid,
  onSaved,
}: {
  /** Absent when the reader is consenting for themselves. */
  studentUuid?: string;
  onSaved?: () => void;
}) {
  const [categories, setCategories] = useState<DataCategory[] | null>(null);
  const [processors, setProcessors] = useState<DataProcessor[]>([]);
  const [chosen, setChosen] = useState<Set<string>>(new Set());
  const [version, setVersion] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    Promise.all([compliance.categories(), compliance.policy()])
      .then(([catalogue, policy]) => {
        setCategories(catalogue.data);
        setProcessors(catalogue.processors);
        setVersion(policy.version);
        // Optional categories start UNCHECKED (FR-065's rule, applied here):
        // a pre-ticked box is not a choice, and the required ones need no tick
        // because they cannot be refused.
        setChosen(new Set());
      })
      .catch((cause) => setError(userMessage(cause)));
  }, []);

  const toggle = (key: string) => {
    setChosen((current) => {
      const next = new Set(current);
      next.has(key) ? next.delete(key) : next.add(key);

      return next;
    });
  };

  const submit = async () => {
    setSaving(true);
    setError("");

    try {
      await compliance.updateCategories({
        // ⚠️ THE COMPLETE SET, and the server adds the required ones back rather
        // than refusing — a required category cannot be withdrawn, so the honest
        // answer is that it is stored regardless.
        categories: [...chosen],
        version,
        student_uuid: studentUuid,
      });

      setSaved(true);
      onSaved?.();
    } catch (cause) {
      setError(userMessage(cause));
    } finally {
      setSaving(false);
    }
  };

  if (error !== "" && categories === null) {
    return <ErrorState description={error} />;
  }

  if (categories === null) {
    return <RowsSkeleton />;
  }

  const required = categories.filter((category) => category.is_required);
  const optional = categories.filter((category) => !category.is_required);

  return (
    <div className="space-y-6">
      <Card>
        <h3 className="mb-1 font-semibold text-ink">ما نجمعه ولا تعمل الخدمة بدونه</h3>
        <p className="mb-4 text-sm text-ink-muted">
          هذه الأصناف لازمة. لا يمكن سحب الموافقة عليها مع بقاء الحساب فعّالاً.
        </p>

        <ul className="divide-y divide-line">
          {required.map((category) => (
            <li key={category.key} className="py-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-ink">{category.label}</span>
                <Badge tone="neutral">لازم</Badge>
              </div>
              <p className="mt-1 text-sm text-ink-muted">{category.purpose}</p>
              <p className="mt-1 text-xs text-ink-muted">
                يطّلع عليه: {category.audience} · مدة الحفظ: {category.retention_label_ar}
              </p>
            </li>
          ))}
        </ul>
      </Card>

      <Card>
        <h3 className="mb-1 font-semibold text-ink">أصناف اختيارية</h3>
        <p className="mb-4 text-sm text-ink-muted">
          يمكنك سحب الموافقة عن أيٍّ منها في أي وقت، وتتوقّف معالجته فوراً.
        </p>

        {optional.length === 0 ? (
          <p className="text-sm text-ink-muted">لا توجد أصناف اختيارية حالياً.</p>
        ) : (
          <ul className="space-y-3">
            {optional.map((category) => (
              <li key={category.key}>
                {/* The detail rides INSIDE the label, because `CheckboxField`
                    takes a ReactNode and no free-form className — and because a
                    purpose rendered outside the label is not read out when the
                    control is focused. */}
                <CheckboxField
                  id={`category-${category.key}`}
                  checked={chosen.has(category.key)}
                  onChange={() => toggle(category.key)}
                  label={
                    <span>
                      <span className="font-medium text-ink">{category.label}</span>
                      <span className="mt-1 block text-sm text-ink-muted">
                        {category.purpose}
                      </span>
                      <span className="mt-1 block text-xs text-ink-muted">
                        يطّلع عليه: {category.audience} · مدة الحفظ: {category.retention_label_ar}
                      </span>
                    </span>
                  }
                />
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* FR-024 — beside the categories, not on another screen. Someone reading
          about their child's recording should not have to go looking for who
          stores it. */}
      <Card>
        <h3 className="mb-1 font-semibold text-ink">من يصله بيان ابنك خارج خوادمنا</h3>
        <p className="mb-4 text-sm text-ink-muted">
          بعض الخدمات تعمل عند مزوّدين. هذه قائمتهم، ومكان المعالجة، وهل يستطيع كلٌّ منهم
          الحذف عند الطلب.
        </p>

        <ul className="divide-y divide-line">
          {processors.map((processor) => (
            <li key={processor.key} className="py-3">
              <span className="font-medium text-ink">{processor.name}</span>
              <p className="mt-1 text-sm text-ink-muted">{processor.purpose}</p>
              <p className="mt-1 text-xs text-ink-muted">
                مكان المعالجة: {processor.processing_location} ·{" "}
                {processor.erasure_capability_label_ar}
              </p>
            </li>
          ))}
        </ul>
      </Card>

      {error !== "" && <Alert tone="danger" title="تعذّر الحفظ">{error}</Alert>}
      {saved && <Alert tone="success" title="حُفظ اختيارك">يمكنك تعديله متى شئت.</Alert>}

      <Button onClick={submit} loading={saving} disabled={version === ""}>
        أوافق على ما سبق
      </Button>
    </div>
  );
}
