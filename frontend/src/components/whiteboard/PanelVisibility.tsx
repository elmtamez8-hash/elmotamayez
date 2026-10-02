"use client";

import { useEffect, useState, type RefObject } from "react";

import { PANELS, PANEL_MODES, isNear, type PanelId, type PanelMode } from "@/lib/whiteboard/panels";
import { WB } from "@/lib/whiteboard/strings";

type Modes = Partial<Record<PanelId, PanelMode>>;

const scoped = (selector: string, suffix = "") =>
  selector
    .split(",")
    .map((part) => `.wb-board ${part.trim()}${suffix}`)
    .join(", ");

function rectOf(root: HTMLElement, selector: string): DOMRect | null {
  const found = [...root.querySelectorAll<HTMLElement>(selector)].map((el) => el.getBoundingClientRect()).filter((r) => r.width > 0);
  if (found.length === 0) return null;
  const left = Math.min(...found.map((r) => r.left));
  const top = Math.min(...found.map((r) => r.top));
  return new DOMRect(left, top, Math.max(...found.map((r) => r.right)) - left, Math.max(...found.map((r) => r.bottom)) - top);
}

/**
 * Hides the panels the teacher folded or set to «يظهر لما تقرّب». Hidden by
 * `visibility`, so a hidden panel keeps its place and can still be measured.
 * A folded panel leaves a small tab where it was; an «auto» one comes back
 * while the pointer is near it — never while a stroke is being drawn.
 */
export function PanelVisibility({ root, modes, onMode }: { root: RefObject<HTMLElement | null>; modes: Modes; onMode: (id: PanelId, mode: PanelMode) => void }) {
  const [near, setNear] = useState<PanelId[]>([]);
  const [tabs, setTabs] = useState<{ id: PanelId; rect: DOMRect }[]>([]);
  const auto = PANELS.filter(({ id }) => modes[id] === "auto");
  const autoKey = auto.map(({ id }) => id).join();

  useEffect(() => {
    if (auto.length === 0) return;
    const onMove = (event: PointerEvent) => {
      if (event.buttons !== 0 || !root.current) return;
      const target = event.target instanceof Node ? event.target : null;
      const next = auto
        .filter(({ selector }) => {
          const el = root.current?.querySelector(selector);
          if (el && target && el.contains(target)) return true;
          const rect = root.current ? rectOf(root.current, selector) : null;
          return rect !== null && isNear(rect, event.clientX, event.clientY);
        })
        .map(({ id }) => id);
      setNear((prev) => (prev.join() === next.join() ? prev : next));
    };
    window.addEventListener("pointermove", onMove);
    return () => window.removeEventListener("pointermove", onMove);
  }, [autoKey, root]);

  const foldedKey = PANELS.filter(({ id }) => modes[id] === "folded")
    .map(({ id }) => id)
    .join();
  useEffect(() => {
    const measure = () => {
      const el = root.current;
      if (!el) return;
      setTabs(
        PANELS.filter(({ id }) => modes[id] === "folded").flatMap(({ id, selector }) => {
          const rect = rectOf(el, selector);
          return rect ? [{ id, rect }] : [];
        }),
      );
    };
    // Now (an unfolded panel's tab goes at once), and again once Excalidraw has laid its panels out.
    measure();
    const frame = requestAnimationFrame(measure);
    window.addEventListener("resize", measure);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener("resize", measure);
    };
  }, [foldedKey, root]);

  const hidden = PANELS.filter(({ id }) => modes[id] === "folded" || (modes[id] === "auto" && !near.includes(id)));

  return (
    <>
      <style>{`
        ${PANELS.map(({ selector }) => scoped(selector)).join(", ")} { transition: opacity 150ms, visibility 150ms; }
        ${hidden.length ? `${hidden.map(({ selector }) => `${scoped(selector)}, ${scoped(selector, " *")}`).join(", ")} { opacity: 0; visibility: hidden !important; }` : ""}
      `}</style>
      {tabs.map(({ id, rect }) => (
        <button
          key={id}
          type="button"
          className="fixed rounded-b-lg border border-t-0 border-line bg-surface-raised px-2 py-0.5 text-xs text-ink shadow-sm"
          style={{ zIndex: 6, top: rect.top, left: rect.left + rect.width / 2, transform: "translateX(-50%)" }}
          onClick={() => onMode(id, "shown")}
        >
          {WB.panelUnfold(WB.panels[id])}
        </button>
      ))}
    </>
  );
}

/** The choice for every panel, in the «عرض» menu. */
export function PanelModesMenu({ modes, onMode }: { modes: Modes; onMode: (id: PanelId, mode: PanelMode) => void }) {
  return (
    <div className="flex flex-col gap-1">
      {PANELS.map(({ id }) => (
        <label key={id} className="flex items-center justify-between gap-2 text-sm">
          <span>{WB.panels[id]}</span>
          <select
            className="rounded-md border border-line bg-surface px-1 py-0.5 text-sm"
            value={modes[id] ?? "shown"}
            onChange={(event) => onMode(id, event.target.value as PanelMode)}
          >
            {PANEL_MODES.map((mode) => (
              <option key={mode} value={mode}>
                {WB.panelModes[mode]}
              </option>
            ))}
          </select>
        </label>
      ))}
    </div>
  );
}
