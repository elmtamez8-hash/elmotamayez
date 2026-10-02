"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { SelectField, TextField } from "@/components/ui/Field";
import type { BoardBackground } from "@/lib/whiteboard/page-model";
import { WB } from "@/lib/whiteboard/strings";

const BACKGROUND_OPTIONS = (Object.keys(WB.backgrounds) as BoardBackground[]).map((value) => ({
  value,
  label: WB.backgrounds[value],
}));

/**
 * The board's name and background, in the board itself (US1, FR-001, FR-007).
 * Changing the background is immediate — the canvas recolours what is drawn — and
 * the caller saves it. Renaming opens a small field and saves on «حفظ».
 */
export function BoardSettings({
  title,
  background,
  disabled,
  onRename,
  onBackground,
}: {
  title: string;
  background: BoardBackground;
  disabled?: boolean;
  onRename: (title: string) => Promise<void>;
  onBackground: (background: BoardBackground) => void;
}) {
  const [renaming, setRenaming] = useState(false);
  const [draft, setDraft] = useState(title);
  const [saving, setSaving] = useState(false);

  const save = async () => {
    const next = draft.trim();
    if (next === "" || next === title) {
      setRenaming(false);
      return;
    }
    setSaving(true);
    try {
      await onRename(next);
      setRenaming(false);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex items-end gap-2">
      {renaming ? (
        <>
          <TextField id="wb-title" label={WB.title} value={draft} onChange={setDraft} maxLength={160} />
          <Button size="sm" loading={saving} onClick={save}>
            {WB.save}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setRenaming(false)}>
            {WB.cancel}
          </Button>
        </>
      ) : (
        <Button size="sm" variant="ghost" disabled={disabled} onClick={() => { setDraft(title); setRenaming(true); }}>
          {WB.rename}
        </Button>
      )}
      <SelectField
        id="wb-background"
        label={WB.background}
        labelHidden
        value={background}
        disabled={disabled}
        options={BACKGROUND_OPTIONS}
        onChange={(value) => onBackground(value as BoardBackground)}
      />
    </div>
  );
}
