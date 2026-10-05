"use client";

import { useCallback, useEffect, useState } from "react";

import { UsersIcon } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { ApiError } from "@/lib/api";
import { errorCode, userMessage } from "@/lib/errors";
import { boardLibrary, type SharedShape } from "@/lib/whiteboard/api";
import {
  DefaultSidebar,
  dragShape,
  placeShape,
  selectedShape,
  shapePreview,
  Sidebar,
  type BoardApi,
  type BoardElement,
} from "@/lib/whiteboard/excalidraw-api";
import { WB } from "@/lib/whiteboard/strings";

const TAB = "academy";

/**
 * The academy's shared board library (owner decisions 2026-10-05) — a tab of
 * Excalidraw's own «مكتبة» sidebar. Every teacher sees it; the one holding the
 * pen places a shape (press or drag) and shares the selection; the server says
 * who may remove what (`can_delete`).
 */
export function AcademyLibrary({ api, canEdit }: { api: BoardApi | null; canEdit: boolean }) {
  return (
    <DefaultSidebar>
      <DefaultSidebar.TabTriggers>
        {/* An icon, as Excalidraw's own tab: the trigger is a square, and the word did not fit in it. */}
        <Sidebar.TabTrigger tab={TAB} title={WB.academy.title} aria-label={WB.academy.title}>
          <UsersIcon className="h-5 w-5" />
        </Sidebar.TabTrigger>
      </DefaultSidebar.TabTriggers>
      <Sidebar.Tab tab={TAB}>
        <AcademyShapes api={api} canEdit={canEdit} />
      </Sidebar.Tab>
    </DefaultSidebar>
  );
}

function AcademyShapes({ api, canEdit }: { api: BoardApi | null; canEdit: boolean }) {
  const [shapes, setShapes] = useState<SharedShape[] | null>(null);
  const [error, setError] = useState("");
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    boardLibrary
      .list()
      .then(({ data }) => setShapes(data))
      .catch(() => setError(WB.academy.loadFailed));
  }, []);
  useEffect(load, [load]);

  const share = async () => {
    if (!api) return;
    const shape = selectedShape(api);
    // An uploaded picture belongs to its own board; a template draws anywhere.
    const portable = shape.filter((e) => e.type !== "image" || String((e as { fileId?: string }).fileId ?? "").startsWith("template:"));
    if (portable.length === 0 || !name.trim()) {
      setError(WB.academy.selectFirst);
      return;
    }
    setBusy(true);
    setError(portable.length < shape.length ? WB.academy.pictures : "");
    try {
      await boardLibrary.share({ name: name.trim(), elements: portable });
      setName("");
      load();
    } catch (err: unknown) {
      const code = err instanceof ApiError ? errorCode(err.body) : null;
      setError(code === "library_item_too_large" ? WB.academy.tooLarge : code === "library_full" ? WB.academy.full : userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (uuid: string) => {
    try {
      await boardLibrary.remove(uuid);
      setShapes((list) => list?.filter((s) => s.uuid !== uuid) ?? null);
    } catch (err: unknown) {
      setError(userMessage(err));
    }
  };

  return (
    <div dir="rtl" className="flex h-full flex-col gap-3 overflow-y-auto p-3 text-ink">
      <p className="text-xs text-ink-muted">{WB.academy.intro}</p>

      {canEdit && (
        <div className="flex flex-col gap-2 rounded-lg border border-line p-2">
          <input
            aria-label={WB.academy.name}
            placeholder={WB.academy.name}
            value={name}
            maxLength={80}
            onChange={(e) => setName(e.target.value)}
            className="rounded border border-line bg-surface px-2 py-1 text-sm"
          />
          <Button size="sm" onClick={() => void share()} loading={busy}>{WB.academy.share}</Button>
        </div>
      )}
      {error && <p role="alert" className="text-xs text-danger">{error}</p>}

      {shapes?.length === 0 && <p className="text-sm text-ink-muted">{WB.academy.empty}</p>}
      <ul className="grid grid-cols-2 gap-2">
        {shapes?.map((shape) => (
          <li key={shape.uuid} className="flex flex-col gap-1 rounded-lg border border-line p-1">
            <button
              type="button"
              disabled={!canEdit || !api}
              draggable={canEdit}
              onDragStart={(event) => dragShape(event, shape.uuid, shape.elements as BoardElement[])}
              onClick={() => api && placeShape(api, shape.elements as BoardElement[])}
              className="flex aspect-square items-center justify-center rounded bg-surface p-1 disabled:cursor-default"
              title={shape.name}
            >
              <Preview elements={shape.elements as BoardElement[]} name={shape.name} />
            </button>
            <span className="truncate text-xs font-medium">{shape.name}</span>
            {shape.shared_by && <span className="truncate text-[11px] text-ink-muted">{WB.academy.sharedBy(shape.shared_by)}</span>}
            {shape.can_delete && (
              <ConfirmButton size="sm" variant="ghost" confirmLabel={WB.academy.confirmRemove} onConfirm={() => void remove(shape.uuid)}>
                {WB.academy.remove}
              </ConfirmButton>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

function Preview({ elements, name }: { elements: BoardElement[]; name: string }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    let made: string | null = null;
    let stale = false;
    shapePreview(elements)
      .then((u) => {
        made = u;
        if (!stale) setUrl(u);
        else URL.revokeObjectURL(u);
      })
      .catch(() => undefined);
    return () => {
      stale = true;
      if (made) URL.revokeObjectURL(made);
    };
  }, [elements]);
  // eslint-disable-next-line @next/next/no-img-element -- a blob drawn here, not a page asset
  return url ? <img src={url} alt={name} className="max-h-full max-w-full" /> : null;
}
