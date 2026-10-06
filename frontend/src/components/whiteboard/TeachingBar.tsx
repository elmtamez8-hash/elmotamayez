"use client";

import { MenuChip, MenuRow } from "@/components/whiteboard/MenuParts";
import type { InstrumentKind } from "@/lib/whiteboard/geometry";
import { PENS, type PenId } from "@/lib/whiteboard/pens";
import { WB } from "@/lib/whiteboard/strings";
import { TEMPLATES, type TemplateName } from "@/lib/whiteboard/templates";

export type PassingTool = "magnifier" | "curtain" | "wheel";

export interface TeachingBarProps {
  /** Templates and pens change the page or the pen, so they are the editor's alone. */
  canEdit: boolean;
  template: TemplateName | null;
  open: PassingTool | null;
  onTemplate: (name: TemplateName | null) => void;
  onPen: (pen: PenId) => void;
  /** Whether «القلم السحري» is the pen in hand. */
  magic?: boolean;
  onTool: (tool: PassingTool) => void;
  instrument: InstrumentKind | null;
  onInstrument: (kind: InstrumentKind) => void;
  /** Story 6: a table, edited in its own editor. */
  onTable: () => void;
  /** Story 6: an equation, in MathLive with its LaTeX beside it. */
  onMath: () => void;
  onGraph: () => void;
  /** The floating scientific calculator. */
  onCalculator: () => void;
}

/** The «أدوات» menu (US9): page templates, ready pens, and the passing tools. */
export function TeachingBar(props: TeachingBarProps) {
  return (
    <div className="flex flex-col gap-2.5">
      {props.canEdit && (
        <>
          <MenuRow label={WB.tools.template}>
            <MenuChip active={props.template === null} onClick={() => props.onTemplate(null)}>
              {WB.tools.templates.none}
            </MenuChip>
            {TEMPLATES.map((name) => (
              <MenuChip key={name} active={props.template === name} onClick={() => props.onTemplate(name)}>
                {WB.tools.templates[name]}
              </MenuChip>
            ))}
          </MenuRow>
          <MenuRow label={WB.tools.pen}>
            {PENS.map((pen) => (
              <MenuChip
                key={pen.id}
                active={pen.id === "magic" ? Boolean(props.magic) : undefined}
                expanded={pen.id === "magic" ? Boolean(props.magic) : undefined}
                onClick={() => props.onPen(pen.id)}
              >
                {WB.tools.pens[pen.id]}
              </MenuChip>
            ))}
            {props.magic && <p className="w-full text-xs text-ink-muted">{WB.tools.magicHint}</p>}
          </MenuRow>
          <MenuRow label={WB.tools.geometry}>
            {(["ruler", "set-square", "protractor", "compass"] as const).map((kind) => (
              <MenuChip key={kind} active={props.instrument === kind} expanded={props.instrument === kind} onClick={() => props.onInstrument(kind)}>
                {WB.tools.instruments[kind]}
              </MenuChip>
            ))}
          </MenuRow>
          <MenuRow label={WB.tools.insert}>
            <MenuChip onClick={props.onTable}>{WB.table.insert}</MenuChip>
            <MenuChip onClick={props.onMath}>{WB.math.insert}</MenuChip>
            <MenuChip onClick={props.onGraph}>{WB.graph.insert}</MenuChip>
            <MenuChip onClick={props.onCalculator}>{WB.calc.open}</MenuChip>
          </MenuRow>
        </>
      )}
      <MenuRow label={WB.tools.passing}>
        {(["magnifier", "curtain", "wheel"] as const).map((tool) => (
          <MenuChip key={tool} active={props.open === tool} expanded={props.open === tool} onClick={() => props.onTool(tool)}>
            {WB.tools.names[tool]}
          </MenuChip>
        ))}
      </MenuRow>
    </div>
  );
}
