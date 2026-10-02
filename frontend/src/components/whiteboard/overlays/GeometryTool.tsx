"use client";

import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from "react";

import { arabicDigits } from "@/lib/numerals";
import {
  arcPoints,
  bearing,
  centimetres,
  CM,
  distance,
  INSTRUMENT_SIZE,
  protractorAngle,
  protractorAngleStrokes,
  protractorRim,
  RULER_WIDTH,
  snapToEdge,
  sweep,
  type Instrument,
  type InstrumentKind,
  type Point,
} from "@/lib/whiteboard/geometry";
import { WB } from "@/lib/whiteboard/strings";

/**
 * A geometry instrument on the page (US9, FR-029): ruler, protractor, compass or
 * set square.
 *
 * ⚠️ IT LIVES IN PAGE UNITS. The layer it sits in carries the board's own scroll
 * and zoom as a CSS transform (`OverlayLayer`), so the instrument stays on the
 * same spot of the page while the teacher pans and zooms, and a centimetre on it
 * is a centimetre on the page.
 *
 * Drag its body to move it, the small round handle to turn it (the compass:
 * its pin to move, its handle to open it). Draw in the band along an edge: the
 * line is snapped to the edge, shown as it is drawn with its length, and becomes
 * an ordinary line on the page when the pen lifts. The instrument is never saved.
 */

export interface View {
  scrollX: number;
  scrollY: number;
  zoom: number;
}

/** The board's scroll and zoom as a transform: children are placed in page units. */
export function OverlayLayer({ view, children }: { view: View; children: React.ReactNode }) {
  return (
    <div className="pointer-events-none absolute inset-0 overflow-hidden" style={{ zIndex: 5 }} data-overlay-layer>
      <div
        className="absolute left-0 top-0"
        style={{ transformOrigin: "0 0", transform: `scale(${view.zoom}) translate(${view.scrollX}px, ${view.scrollY}px)` }}
      >
        {children}
      </div>
    </div>
  );
}

const INK = "#1f2937";
const BODY = "rgba(254, 243, 199, 0.82)";
const LANE = 64;

type Gesture =
  | { type: "move"; from: Point; start: Instrument }
  | { type: "turn"; start: Instrument; from: number }
  | { type: "open" }
  | { type: "line"; from: Point; to: Point }
  | { type: "ray"; angle: number }
  | { type: "arc"; start: number; last: number; span: number };

export function GeometryTool({
  kind,
  view,
  centre,
  onDraw,
  onClose,
}: {
  kind: InstrumentKind;
  view: View;
  /** Where to place it first: the middle of the page, in page units. */
  centre: Point;
  onDraw: (points: [number, number][]) => void;
  onClose: () => void;
}) {
  const [inst, setInst] = useState<Instrument>(() => {
    const size = INSTRUMENT_SIZE[kind];
    const offset = kind === "ruler" ? -size / 2 : kind === "set-square" ? -size / 3 : 0;
    return { kind, x: centre[0] + offset, y: centre[1] + (kind === "set-square" ? size / 3 : 0), rotation: 0, size };
  });
  const [gesture, setGesture] = useState<Gesture | null>(null);
  const svg = useRef<SVGSVGElement>(null);
  const viewRef = useRef(view);
  viewRef.current = view;

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  /** A pointer on screen, in page units. */
  const toPage = (event: ReactPointerEvent): Point => {
    const box = svg.current?.closest("[data-overlay-layer]")?.getBoundingClientRect();
    const { zoom, scrollX, scrollY } = viewRef.current;
    return [(event.clientX - (box?.left ?? 0)) / zoom - scrollX, (event.clientY - (box?.top ?? 0)) / zoom - scrollY];
  };

  const begin = (event: ReactPointerEvent, next: Gesture) => {
    event.stopPropagation();
    event.currentTarget.setPointerCapture?.(event.pointerId);
    setGesture(next);
  };

  const onMove = (event: ReactPointerEvent) => {
    if (!gesture) return;
    const p = toPage(event);
    switch (gesture.type) {
      case "move":
        setInst({ ...gesture.start, x: gesture.start.x + p[0] - gesture.from[0], y: gesture.start.y + p[1] - gesture.from[1] });
        break;
      case "turn":
        setInst({ ...gesture.start, rotation: Math.round(gesture.start.rotation + bearing([gesture.start.x, gesture.start.y], p) - gesture.from) });
        break;
      case "open":
        setInst((i) => ({ ...i, size: Math.max(CM, distance([i.x, i.y], p)), rotation: bearing([i.x, i.y], p) }));
        break;
      case "line": {
        const snapped = snapToEdge(inst, p);
        if (snapped) setGesture({ ...gesture, to: snapped.point });
        break;
      }
      case "ray":
        setGesture({ type: "ray", angle: protractorAngle(inst, p) });
        break;
      case "arc": {
        const now = bearing([inst.x, inst.y], p);
        setGesture({ ...gesture, last: now, span: gesture.span + sweep(gesture.last, now) });
        break;
      }
    }
  };

  const onUp = () => {
    if (!gesture) return;
    if (gesture.type === "line" && distance(gesture.from, gesture.to) > 4) onDraw([gesture.from, gesture.to]);
    if (gesture.type === "ray") for (const stroke of protractorAngleStrokes(inst, gesture.angle)) onDraw(stroke);
    if (gesture.type === "arc" && Math.abs(gesture.span) > 2) onDraw(arcPoints([inst.x, inst.y], inst.size, gesture.start, gesture.span));
    setGesture(null);
  };

  const startMove = (event: ReactPointerEvent) => begin(event, { type: "move", from: toPage(event), start: inst });
  const startTurn = (event: ReactPointerEvent) =>
    begin(event, { type: "turn", start: inst, from: bearing([inst.x, inst.y], toPage(event)) });
  const startLine = (event: ReactPointerEvent) => {
    const snapped = snapToEdge(inst, toPage(event));
    if (snapped) begin(event, { type: "line", from: snapped.point, to: snapped.point });
  };
  const startRay = (event: ReactPointerEvent) => begin(event, { type: "ray", angle: protractorAngle(inst, toPage(event)) });
  const startArc = (event: ReactPointerEvent) => {
    const b = bearing([inst.x, inst.y], toPage(event));
    begin(event, { type: "arc", start: b, last: b, span: 0 });
  };

  const handlers = { onPointerMove: onMove, onPointerUp: onUp, onPointerCancel: onUp };
  const grab = { pointerEvents: "auto" as const, cursor: "grab" };
  const s = inst.size;

  return (
    <svg ref={svg} width={1} height={1} overflow="visible" className="absolute left-0 top-0" style={{ pointerEvents: "none" }} data-instrument={kind}>
      <g transform={`rotate(${inst.rotation} ${inst.x} ${inst.y})`}>
        {kind === "ruler" && (
          <>
            {/* The drawing band, above the edge; the body below it moves the ruler. */}
            <rect x={inst.x} y={inst.y - LANE} width={s} height={LANE} fill="transparent" style={{ pointerEvents: "auto", cursor: "crosshair" }} onPointerDown={startLine} {...handlers} data-lane />
            <rect x={inst.x} y={inst.y} width={s} height={RULER_WIDTH} fill={BODY} stroke={INK} strokeWidth={2} rx={6} style={grab} onPointerDown={startMove} {...handlers} />
            {Array.from({ length: Math.floor(s / (CM / 2)) + 1 }, (_, i) => {
              const x = inst.x + (i * CM) / 2;
              const whole = i % 2 === 0;
              return (
                <g key={i} pointerEvents="none">
                  <line x1={x} y1={inst.y} x2={x} y2={inst.y + (whole ? 22 : 12)} stroke={INK} strokeWidth={whole ? 2 : 1} />
                  {whole && (
                    <text x={x} y={inst.y + 42} textAnchor="middle" fontSize={16} fill={INK}>
                      {arabicDigits(i / 2)}
                    </text>
                  )}
                </g>
              );
            })}
            <Handle at={[inst.x + s - 18, inst.y + RULER_WIDTH - 18]} onPointerDown={startTurn} handlers={handlers} label={WB.tools.turn} />
          </>
        )}

        {kind === "set-square" && (
          <>
            {/* Bands along all three edges; the triangle itself moves the square. */}
            <polyline points={`${inst.x},${inst.y - s} ${inst.x},${inst.y} ${inst.x + s},${inst.y} ${inst.x},${inst.y - s}`} fill="none" stroke="transparent" strokeWidth={LANE} style={{ pointerEvents: "stroke", cursor: "crosshair" }} onPointerDown={startLine} {...handlers} data-lane />
            <polygon points={`${inst.x},${inst.y} ${inst.x + s},${inst.y} ${inst.x},${inst.y - s}`} fill={BODY} stroke={INK} strokeWidth={2} style={grab} onPointerDown={startMove} {...handlers} />
            <polyline points={`${inst.x + 30},${inst.y} ${inst.x + 30},${inst.y - 30} ${inst.x},${inst.y - 30}`} fill="none" stroke={INK} strokeWidth={1.5} pointerEvents="none" />
            {Array.from({ length: Math.floor(s / CM) + 1 }, (_, i) => (
              <line key={i} x1={inst.x + i * CM} y1={inst.y} x2={inst.x + i * CM} y2={inst.y - 16} stroke={INK} strokeWidth={1.5} pointerEvents="none" />
            ))}
            <Handle at={[inst.x + 70, inst.y - 70]} onPointerDown={startTurn} handlers={handlers} label={WB.tools.turn} />
          </>
        )}

        {kind === "protractor" && (
          <>
            {/* The band round the rim draws a ray from the centre at that angle. */}
            <path d={`M ${inst.x - s} ${inst.y} A ${s} ${s} 0 0 1 ${inst.x + s} ${inst.y}`} fill="none" stroke="transparent" strokeWidth={LANE} style={{ pointerEvents: "stroke", cursor: "crosshair" }} onPointerDown={startRay} {...handlers} data-lane />
            <path d={`M ${inst.x - s} ${inst.y} A ${s} ${s} 0 0 1 ${inst.x + s} ${inst.y} Z`} fill={BODY} stroke={INK} strokeWidth={2} style={grab} onPointerDown={startMove} {...handlers} />
            {Array.from({ length: 37 }, (_, i) => {
              const a = i * 5;
              const outer = protractorRim({ ...inst, rotation: 0 }, a);
              const inner = protractorRim({ ...inst, rotation: 0, size: s - (a % 10 === 0 ? 22 : 12) }, a);
              const label = protractorRim({ ...inst, rotation: 0, size: s - 38 }, a);
              return (
                <g key={a} pointerEvents="none">
                  <line x1={inner[0]} y1={inner[1]} x2={outer[0]} y2={outer[1]} stroke={INK} strokeWidth={a % 10 === 0 ? 2 : 1} />
                  {a % 10 === 0 && (
                    <>
                      <text x={label[0]} y={label[1]} textAnchor="middle" dominantBaseline="middle" fontSize={13} fontWeight={700} fill={INK}>
                        {arabicDigits(a)}
                      </text>
                      <text
                        x={protractorRim({ ...inst, rotation: 0, size: s - 58 }, a)[0]}
                        y={protractorRim({ ...inst, rotation: 0, size: s - 58 }, a)[1]}
                        textAnchor="middle"
                        dominantBaseline="middle"
                        fontSize={11}
                        fill="#b45309"
                      >
                        {arabicDigits(180 - a)}
                      </text>
                    </>
                  )}
                </g>
              );
            })}
            <circle cx={inst.x} cy={inst.y} r={4} fill={INK} pointerEvents="none" />
            <Handle at={[inst.x + s - 16, inst.y - 16]} onPointerDown={startTurn} handlers={handlers} label={WB.tools.turn} />
          </>
        )}

        {kind === "compass" && (
          <>
            {/* The band on the circle draws the arc; the pin moves; the handle opens it. */}
            <circle cx={inst.x} cy={inst.y} r={s} fill="none" stroke="transparent" strokeWidth={LANE} style={{ pointerEvents: "stroke", cursor: "crosshair" }} onPointerDown={startArc} {...handlers} data-lane />
            <circle cx={inst.x} cy={inst.y} r={s} fill="none" stroke={INK} strokeWidth={1.5} strokeDasharray="8 8" pointerEvents="none" />
            <line x1={inst.x} y1={inst.y} x2={inst.x + s} y2={inst.y} stroke={INK} strokeWidth={3} pointerEvents="none" />
            <circle cx={inst.x} cy={inst.y} r={14} fill={BODY} stroke={INK} strokeWidth={2} style={grab} onPointerDown={startMove} {...handlers} />
            <Handle at={[inst.x + s, inst.y]} onPointerDown={(event) => begin(event, { type: "open" })} handlers={handlers} label={WB.tools.open} />
            <text x={inst.x + s / 2} y={inst.y - 12} textAnchor="middle" fontSize={16} fill={INK} pointerEvents="none">
              {arabicDigits(centimetres(s))} {WB.tools.cm}
            </text>
          </>
        )}
      </g>

      {/* What is being drawn, before it is a line on the page. */}
      {gesture?.type === "line" && (
        <g pointerEvents="none">
          <line x1={gesture.from[0]} y1={gesture.from[1]} x2={gesture.to[0]} y2={gesture.to[1]} stroke="#d62828" strokeWidth={4} />
          <Badge at={gesture.to} text={`${arabicDigits(centimetres(distance(gesture.from, gesture.to)))} ${WB.tools.cm}`} />
        </g>
      )}
      {gesture?.type === "ray" && (
        <g pointerEvents="none">
          {protractorAngleStrokes(inst, gesture.angle).map((stroke, i) => (
            <polyline key={i} points={stroke.map((p) => p.join(",")).join(" ")} fill="none" stroke="#d62828" strokeWidth={4} />
          ))}
          <Badge at={protractorRim(inst, gesture.angle)} text={`${arabicDigits(gesture.angle)}° / ${arabicDigits(180 - gesture.angle)}°`} />
        </g>
      )}
      {gesture?.type === "arc" && (
        <polyline points={arcPoints([inst.x, inst.y], s, gesture.start, gesture.span).map((p) => p.join(",")).join(" ")} fill="none" stroke="#d62828" strokeWidth={4} pointerEvents="none" />
      )}
    </svg>
  );
}

function Handle({
  at,
  onPointerDown,
  handlers,
  label,
}: {
  at: Point;
  onPointerDown: (event: ReactPointerEvent) => void;
  handlers: Record<string, (event: ReactPointerEvent) => void>;
  label: string;
}) {
  return (
    <circle cx={at[0]} cy={at[1]} r={12} fill="#2563eb" stroke="#fff" strokeWidth={3} style={{ pointerEvents: "auto", cursor: "alias" }} onPointerDown={onPointerDown} {...handlers}>
      <title>{label}</title>
    </circle>
  );
}

function Badge({ at, text }: { at: Point; text: string }) {
  return (
    <g transform={`translate(${at[0] + 16} ${at[1] - 36})`}>
      <rect width={140} height={32} rx={8} fill="#111827" />
      <text x={70} y={21} textAnchor="middle" fontSize={18} fontWeight={700} fill="#fff">
        {text}
      </text>
    </g>
  );
}
