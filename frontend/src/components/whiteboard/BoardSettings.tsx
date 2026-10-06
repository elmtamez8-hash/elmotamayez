"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { MenuChip, MenuSelect } from "@/components/whiteboard/MenuParts";
import type { BoardBackground } from "@/lib/whiteboard/page-model";
import { WB } from "@/lib/whiteboard/strings";

const BACKGROUND_OPTIONS = (Object.keys(WB.backgrounds) as BoardBackground[]).map((value) => ({
  value,
  label: WB.backgrounds[value],
}));

/**
 * The board's name and page colour, in the board itself (US1, FR-001, FR-007).
 * The name is shown whole here: the folded bar has room for a few letters of it
 * only. Changing the colour is immediate — the canvas recolours what is drawn —
 * and the caller saves it. Renaming opens a small field and saves on «حفظ».
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
    <div className="flex flex-col gap-2.5">
      {renaming ? (
        <div className="flex items-center gap-2">
          <div className="min-w-0 flex-1">
            <TextField id="wb-title" label={WB.title} labelHidden value={draft} onChange={setDraft} maxLength={160} />
          </div>
          <Button size="sm" loading={saving} onClick={save}>
            {WB.save}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setRenaming(false)}>
            {WB.cancel}
          </Button>
        </div>
      ) : (
        <div className="flex items-start justify-between gap-2">
          <h2 className="min-w-0 break-words pt-0.5 text-base font-bold leading-snug">{title}</h2>
          <MenuChip
            disabled={disabled}
            onClick={() => {
              setDraft(title);
              setRenaming(true);
            }}
          >
            {WB.rename}
          </MenuChip>
        </div>
      )}
      <MenuSelect
        label={WB.background}
        value={background}
        disabled={disabled}
        options={BACKGROUND_OPTIONS}
        onChange={(value: BoardBackground) => onBackground(value)}
      />
    </div>
  );
}
