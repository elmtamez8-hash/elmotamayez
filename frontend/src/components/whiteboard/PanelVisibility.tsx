"use client";

import { useEffect, useState, type RefObject } from "react";

import { PANELS, PANEL_MODES, isNear, type PanelId, type PanelMode } from "@/lib/whiteboard/panels";
import { MenuSelect } from "@/components/whiteboard/MenuParts";
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
export function PanelVisibility({
  root,
  modes,
  onMode,
  layout,
}: {
  root: RefObject<HTMLElement | null>;
  modes: Modes;
  onMode: (id: PanelId, mode: PanelMode) => void;
  /** Changes whenever a panel can appear or vanish (the lock, the pages toggle): the tabs are measured again. */
  layout: string;
}) {
  const [near, setNear] = useState<PanelId[]>([]);
  const [tabs, setTabs] = useState<{ id: PanelId; rect: DOMRect }[]>([]);
  const auto = PANELS.filter(({ id }) => modes[id] === "auto");
  const autoKey = auto.map(({ id }) => id).join();

  useEffect(() => {
    if (auto.length === 0) return;
    const onMove = (event: PointerEvent) => {
      // A mouse or pen with a button down is drawing: no panel pops up over it. A
      // finger has no hover — it only moves while down — so a touch always counts.
      if ((event.buttons !== 0 && event.pointerType !== "touch") || !root.current) return;
      const target = event.target instanceof Node ? event.target : null;
      const next = auto
        .filter(({ selector }) => {
          const el = root.current?.querySelector(selector);
          if (el && target && el.contains(target)) return true;
          // Typing in it (the board's name): hiding it would drop the focus mid-word.
          if (el?.contains(document.activeElement)) return true;
          const rect = root.current ? rectOf(root.current, selector) : null;
          return rect !== null && isNear(rect, event.clientX, event.clientY);
        })
        .map(({ id }) => id);
      setNear((prev) => (prev.join() === next.join() ? prev : next));
    };
    window.addEventListener("pointermove", onMove);
    window.addEventListener("pointerdown", onMove); // a tap near a hidden panel brings it back
    return () => {
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("pointerdown", onMove);
    };
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
  }, [foldedKey, layout, root]);

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
    <div className="flex flex-col gap-2">
      {PANELS.map(({ id }) => (
        <MenuSelect
          key={id}
          label={WB.panels[id]}
          value={modes[id] ?? "shown"}
          options={PANEL_MODES.map((mode) => ({ value: mode, label: WB.panelModes[mode] }))}
          onChange={(mode: PanelMode) => onMode(id, mode)}
        />
      ))}
    </div>
  );
}
