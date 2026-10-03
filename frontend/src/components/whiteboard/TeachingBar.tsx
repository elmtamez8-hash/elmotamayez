"use client";

import { Button } from "@/components/ui/Button";
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
  onTool: (tool: PassingTool) => void;
  instrument: InstrumentKind | null;
  onInstrument: (kind: InstrumentKind) => void;
  /** Story 6: a table, edited in its own editor. */
  onTable: () => void;
}

/** The «أدوات» menu (US9): page templates, ready pens, and the passing tools. */
export function TeachingBar(props: TeachingBarProps) {
  return (
    <div className="flex flex-col gap-1.5 border-t border-line pt-1.5">
      {props.canEdit && (
        <>
          <div className="flex flex-wrap items-center gap-1">
            <span className="text-xs text-ink-muted">{WB.tools.template}</span>
            <Button size="sm" variant={props.template === null ? "secondary" : "ghost"} onClick={() => props.onTemplate(null)}>
              {WB.tools.templates.none}
            </Button>
            {TEMPLATES.map((name) => (
              <Button key={name} size="sm" variant={props.template === name ? "secondary" : "ghost"} onClick={() => props.onTemplate(name)}>
                {WB.tools.templates[name]}
              </Button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-1">
            <span className="text-xs text-ink-muted">{WB.tools.pen}</span>
            {PENS.map((pen) => (
              <Button key={pen.id} size="sm" variant="ghost" onClick={() => props.onPen(pen.id)}>
                {WB.tools.pens[pen.id]}
              </Button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-1">
            <span className="text-xs text-ink-muted">{WB.tools.geometry}</span>
            {(["ruler", "set-square", "protractor", "compass"] as const).map((kind) => (
              <Button key={kind} size="sm" variant={props.instrument === kind ? "secondary" : "ghost"} expanded={props.instrument === kind} onClick={() => props.onInstrument(kind)}>
                {WB.tools.instruments[kind]}
              </Button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-1">
            <Button size="sm" variant="ghost" onClick={props.onTable}>
              {WB.table.insert}
            </Button>
          </div>
        </>
      )}
      <div className="flex flex-wrap items-center gap-1">
        <span className="text-xs text-ink-muted">{WB.tools.passing}</span>
        {(["magnifier", "curtain", "wheel"] as const).map((tool) => (
          <Button key={tool} size="sm" variant={props.open === tool ? "secondary" : "ghost"} expanded={props.open === tool} onClick={() => props.onTool(tool)}>
            {WB.tools.names[tool]}
          </Button>
        ))}
      </div>
    </div>
  );
}
