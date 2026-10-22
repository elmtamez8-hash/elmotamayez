"use client";

import { useEffect, useState } from "react";

import { ShieldIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField } from "@/components/ui/Field";
import { SectionHeading } from "@/components/ui/SectionHeading";
import {
  assistants,
  type AssistantPowerGroup,
  type AssistantPowers,
} from "@/lib/assistants";
import { userMessage } from "@/lib/errors";

const GROUPS: { key: AssistantPowerGroup; label: string; line: string }[] = [
  {
    key: "grading",
    label: "التصحيح",
    line: "تصحيح إجابات الطلاب من لوحة التصحيح.",
  },
  {
    key: "chat",
    label: "الردّ على رسائل الطلاب",
    line: "الردّ في محادثات الطلاب باسم فريقك.",
  },
  {
    key: "sessions",
    label: "الحصص والحضور",
    line: "جدولة الحصص وإدارتها وتقديمها، وتعديل الحضور.",
  },
  {
    key: "exams",
    label: "بنك الأسئلة ونشر الاختبارات",
    line: "إضافة الأسئلة إلى بنك أسئلتك كلّه وتعديلها، ووضعها في الاختبارات ونشرها.",
  },
];

/**
 * «ما يستطيعه مساعدوك» — the teacher's own door to what their assistants may do
 * (owner decision 2026-10-21). Until now the screen sent them to a roles screen
 * in `/admin` they cannot open.
 */
export function AssistantPowersCard() {
  const [powers, setPowers] = useState<AssistantPowers | null>(null);
  const [failed, setFailed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    assistants
      .powers()
      .then((res) => setPowers(res.groups))
      .catch(() => setFailed(true));
  }, []);

  if (failed) return null;
  if (powers === null) return null;

  const save = async () => {
    setSaving(true);
    setError("");
    setSaved(false);
    try {
      const on = GROUPS.filter((g) => powers[g.key]).map((g) => g.key);
      const res = await assistants.setPowers(on);
      setPowers(res.groups);
      setSaved(true);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card>
      <div className="space-y-4">
        <SectionHeading
          id="assistant-powers"
          Icon={ShieldIcon}
          title="ما يستطيعه مساعدوك"
          description="يعدّل كلّ مساعدٍ محتوى الكورسات وينشئ الاختبارات دائماً. وما تختاره هنا يُضاف لكلّ مساعديك، ويبقى من قصرتَه على كورسات بعينها داخلها. ويظهر لهم بعد أن يُعيدوا تحميل الصفحة."
        />
        <div className="space-y-3">
          {GROUPS.map((g) => (
            <CheckboxField
              key={g.key}
              id={`power-${g.key}`}
              checked={powers[g.key]}
              onChange={(checked) => {
                setSaved(false);
                setPowers({ ...powers, [g.key]: checked });
              }}
              label={
                <>
                  <span className="font-medium">{g.label}</span>
                  <span className="block text-ink-muted">{g.line}</span>
                </>
              }
            />
          ))}
        </div>
        {error && (
          <Alert tone="danger" title="لم يُحفظ">
            {error}
          </Alert>
        )}
        {saved && <Alert tone="success" title="حُفظ. يسري على كلّ مساعديك." />}
        <Button onClick={save} loading={saving} loadingLabel="جارٍ الحفظ…">
          حفظ
        </Button>
      </div>
    </Card>
  );
}
