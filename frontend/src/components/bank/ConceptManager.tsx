"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { bank, type Concept } from "@/lib/bank";
import { userMessage } from "@/lib/errors";
import { counted } from "@/lib/labels";

/**
 * The bank's concepts, renamed in place.
 *
 * Concepts are created where they are needed (the question form), so the one
 * management act missing was the rename — a typo in a concept's name is on every
 * filter, every item-analysis row and every practice picker a student opens.
 *
 * ⚠️ THE DEFAULT ROW CANNOT BE RENAMED, AND ITS CONTROL SAYS SO. «غير مصنّف» is
 * the bucket every untagged question fell into; the server refuses to rename it,
 * so the button is not offered rather than answering 403.
 */
export function ConceptManager({
  concepts,
  onRenamed,
}: {
  concepts: Concept[];
  onRenamed: (concept: Concept) => void;
}) {
  const [editing, setEditing] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const start = (concept: Concept) => {
    setEditing(concept.uuid);
    setName(concept.name);
    setError("");
  };

  const save = async (concept: Concept) => {
    setSaving(true);
    setError("");

    try {
      const response = await bank.renameConcept(concept.uuid, name.trim());
      // The server's row carries no count; keep the one the list already had.
      onRenamed({ ...concept, ...response.data, questions_count: concept.questions_count });
      setEditing(null);
    } catch (err: unknown) {
      setError(fieldErrors(err).name ?? userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  if (concepts.length === 0) return null;

  return (
    <Card as="section">
      <h2 className="text-base font-semibold text-ink">الأفكار</h2>
      <p className="mt-1 mb-4 text-sm text-ink-muted">
        الوسوم التي تجمع أسئلتك. تُضاف من نموذج السؤال، وتُعاد تسميتها من هنا فيتغيّر الاسم في كلّ مكان.
      </p>

      <ul className="divide-y divide-line">
        {concepts.map((concept) => (
          <li key={concept.uuid} className="py-3">
            {editing === concept.uuid ? (
              <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto_auto]">
                <TextField
                  id={`concept-${concept.uuid}`}
                  label="الاسم الجديد"
                  value={name}
                  onChange={setName}
                  maxLength={120}
                  required
                  error={error === "" ? undefined : error}
                />
                <Button
                  size="sm"
                  loading={saving}
                  loadingLabel="جارٍ الحفظ…"
                  disabled={name.trim() === "" || name.trim() === concept.name}
                  onClick={() => void save(concept)}
                >
                  احفظ
                </Button>
                <Button variant="ghost" size="sm" disabled={saving} onClick={() => setEditing(null)}>
                  تراجع
                </Button>
              </div>
            ) : (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <span className="font-medium text-ink">{concept.name}</span>
                  {concept.questions_count !== undefined && (
                    <span className="ms-2 text-sm text-ink-muted">
                      {counted(concept.questions_count, {
                        one: "سؤال واحد",
                        two: "سؤالان",
                        few: "أسئلة",
                        many: "سؤالاً",
                        other: "سؤال",
                      })}
                    </span>
                  )}
                </div>
                {concept.is_default ? (
                  <span className="text-xs text-ink-muted">الفكرة الافتراضية لا تُعاد تسميتها</span>
                ) : (
                  <Button variant="ghost" size="sm" onClick={() => start(concept)}>
                    أعِد التسمية <span className="sr-only">{`«${concept.name}»`}</span>
                  </Button>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>
    </Card>
  );
}
