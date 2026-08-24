"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { CheckboxField, SelectField, TextareaField } from "@/components/ui/Field";
import {
  ANNOUNCEMENT_SCOPES,
  type AnnouncementInput,
  type AnnouncementScope,
} from "@/lib/announcements";

/**
 * Writing one notice (spec 010 · US6 · FR-042, FR-044).
 *
 * ⚠️ THE SCOPE PICKER SAYS WHO WILL RECEIVE IT, IN WORDS. A three-way selector
 * labelled «الكلّ / كورس / حصة» is a shape, not an audience — and the difference
 * between «كلّ طالب مسجَّل عندك الآن» and «من حجز مقعداً في هذه الحصة» is the
 * difference between three hundred phones and eight.
 *
 * ⚠️ AND «عاجل» DECLARES WHAT IT DOES BEFORE IT IS TICKED. A flag whose effect is
 * unstated is a flag every teacher ticks — and its whole purpose is to be the
 * exception, reaching a student in the middle of a focus session that a routine
 * notice waits out. The sentence beside it is what keeps it rare.
 *
 * ⚠️ AND THE SCOPE CANNOT BE EDITED AFTERWARDS, so the form only offers it while
 * creating. Once published the audience has been told; moving the scope would
 * leave one group holding a message meant for another and would make the two
 * counters describe an audience that no longer exists.
 */
export function AnnouncementForm({
  onSubmit,
  busy = false,
  error = null,
  courses = [],
  sessions = [],
  initial,
  scopeLocked = false,
}: {
  onSubmit: (input: AnnouncementInput) => void;
  busy?: boolean;
  error?: string | null;
  courses?: Array<{ uuid: string; title: string }>;
  sessions?: Array<{ uuid: string; title: string }>;
  initial?: Partial<AnnouncementInput>;
  scopeLocked?: boolean;
}) {
  const [body, setBody] = useState(initial?.body ?? "");
  const [scope, setScope] = useState<AnnouncementScope>(initial?.scope ?? "all");
  const [scopeUuid, setScopeUuid] = useState(initial?.scope_uuid ?? "");
  const [urgent, setUrgent] = useState(initial?.is_urgent ?? false);

  const targets = scope === "course" ? courses : scope === "session" ? sessions : [];
  /*
   * ⚠️ FALSE WHILE THE SCOPE IS LOCKED, or a course-scoped edit can never be
   * saved: the picker is hidden, so the uuid stays empty and the button stays
   * disabled for ever. The scope is not moving on an edit, so there is nothing
   * to pick.
   */
  const needsTarget = !scopeLocked && scope !== "all";
  const ready = body.trim().length >= 2 && (!needsTarget || scopeUuid !== "");

  const hint = ANNOUNCEMENT_SCOPES.find((option) => option.key === scope)?.hint ?? "";

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        if (!ready || busy) return;

        onSubmit({
          body: body.trim(),
          scope,
          scope_uuid: needsTarget ? scopeUuid : null,
          is_urgent: urgent,
        });
      }}
      className="space-y-4"
      noValidate
    >
      <TextareaField
        id="body"
        label="نصّ الإعلان"
        value={body}
        onChange={setBody}
        rows={4}
        placeholder="حصة الغد تبدأ الساعة الخامسة بدل الرابعة."
        required
        disabled={busy}
      />

      {!scopeLocked && (
        <>
          <SelectField
            id="scope"
            label="من يصله"
            value={scope}
            onChange={(value) => {
              setScope(value as AnnouncementScope);
              // Cleared on every change: a stale uuid from the previous scope is
              // how a course announcement gets published against a session id.
              setScopeUuid("");
            }}
            options={ANNOUNCEMENT_SCOPES.map((option) => ({
              value: option.key,
              label: option.label,
            }))}
            disabled={busy}
          />

          <p className="text-sm text-ink-muted" data-testid="scope-hint">
            {hint}
          </p>

          {needsTarget && (
            <SelectField
              id="scope_uuid"
              label={scope === "course" ? "الكورس" : "الحصة"}
              value={scopeUuid ?? ""}
              onChange={setScopeUuid}
              options={targets.map((target) => ({ value: target.uuid, label: target.title }))}
              placeholder="اختر…"
              disabled={busy}
            />
          )}
        </>
      )}

      <CheckboxField
        id="is_urgent"
        label="إعلان عاجل"
        checked={urgent}
        onChange={setUrgent}
        disabled={busy || scopeLocked}
      />

      <p className="text-sm text-ink-muted" data-testid="urgent-effect">
        {urgent
          ? "يصل الطالب حتى وهو في جلسة تركيز، ولا يستطيع إيقافه من تفضيلاته."
          : "يصل مركز الإشعارات، وينتظر انتهاء جلسة التركيز إن كان الطالب في واحدة."}
      </p>

      {error && (
        <Alert tone="danger" title="تعذّر الحفظ">
          {error}
        </Alert>
      )}

      <Button type="submit" variant="primary" loading={busy} disabled={!ready}>
        حفظ
      </Button>
    </form>
  );
}
