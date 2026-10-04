"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { cellRange, coveredBy, MAX_COLS, MAX_ROWS, mergeCells, normalise, splitCell, TABLE_FILLS, type TableData } from "@/lib/whiteboard/table";
import { WB } from "@/lib/whiteboard/strings";

/** Each arrow's step [rows, columns] in a right-to-left table, where the next column is to the left. */
const STEPS: Record<string, [number, number]> = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, 1], ArrowRight: [0, -1] };
const clamp = (n: number, most: number) => Math.min(most, Math.max(0, n));

/**
 * The table's cells, edited (story 6): rows and columns added and removed, a
 * cell's fill, and its direction. Right to left by default — the first column
 * is the right-hand one (US6-1). Saving hands back the cells; the board draws
 * the picture and puts it in place.
 */
export function TableEditor({
  initial,
  saving,
  onSave,
  onClose,
}: {
  initial: TableData;
  /** Drawing and uploading: one save at a time, and no cancel halfway. */
  saving: boolean;
  onSave: (data: TableData) => void;
  onClose: () => void;
}) {
  const [table, setTable] = useState(() => normalise(initial));
  const [focus, setFocus] = useState<[number, number]>([0, 0]);
  // The selection's other corner (Shift + click); the focused cell is the first.
  const [corner, setCorner] = useState<[number, number] | null>(null);
  const rows = table.rows.length;
  const cols = table.colWidths.length;
  const covered = coveredBy(table);
  // A selection only once Shift + click picked a second cell: a merged cell
  // focused alone is one cell, not a range to merge again or fill underneath.
  const many = corner !== null && (corner[0] !== focus[0] || corner[1] !== focus[1]);
  const range = many ? cellRange(table, focus, corner) : { top: focus[0], left: focus[1], bottom: focus[0], right: focus[1] };
  const selected = (r: number, c: number) => r >= range.top && r <= range.bottom && c >= range.left && c <= range.right;
  const merged = Boolean(table.rows[focus[0]]?.cells[focus[1]]?.span);

  const cell = (r: number, c: number, change: Partial<TableData["rows"][number]["cells"][number]>) =>
    setTable((t) => ({
      ...t,
      rows: t.rows.map((row, ri) => (ri !== r ? row : { cells: row.cells.map((x, ci) => (ci === c ? { ...x, ...change } : x)) })),
    }));
  // A fill goes on every cell selected.
  const fillSelected = (fill: string | undefined) =>
    setTable((t) => ({ ...t, rows: t.rows.map((row, r) => ({ cells: row.cells.map((x, c) => (selected(r, c) ? { ...x, fill } : x)) })) }));

  // A removed row or column cuts a merge short (normalise), and the selection resets.
  const reshape = (change: (t: TableData) => TableData) => {
    setTable((t) => normalise(change(t)));
    setFocus([0, 0]);
    setCorner(null);
  };
  const addRow = () => rows < MAX_ROWS && setTable((t) => normalise({ ...t, rows: [...t.rows, { cells: [] }] }));
  const removeRow = () => rows > 1 && reshape((t) => ({ ...t, rows: t.rows.slice(0, -1) }));
  const addCol = () => cols < MAX_COLS && setTable((t) => normalise({ ...t, colWidths: [...t.colWidths, 240] }));
  const removeCol = () =>
    cols > 1 && reshape((t) => ({ ...t, colWidths: t.colWidths.slice(0, -1), rows: t.rows.map((row) => ({ cells: row.cells.slice(0, -1) })) }));
  const merge = () => {
    setTable((t) => mergeCells(t, range));
    setFocus([range.top, range.left]);
    setCorner(null);
  };

  return (
    <div role="dialog" aria-modal="true" aria-label={WB.table.title} className="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
      <div className="flex max-h-full w-full max-w-4xl flex-col gap-3 overflow-auto rounded-xl bg-surface-raised p-4 text-ink shadow-xl">
        <h2 className="text-lg font-bold">{WB.table.title}</h2>

        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Button size="sm" variant="secondary" onClick={addRow}>{WB.table.addRow}</Button>
          <Button size="sm" variant="secondary" onClick={removeRow} disabled={rows <= 1}>{WB.table.removeRow}</Button>
          <Button size="sm" variant="secondary" onClick={addCol}>{WB.table.addCol}</Button>
          <Button size="sm" variant="secondary" onClick={removeCol} disabled={cols <= 1}>{WB.table.removeCol}</Button>
          <Button size="sm" variant="ghost" onClick={() => setTable((t) => ({ ...t, dir: t.dir === "rtl" ? "ltr" : "rtl" }))}>
            {table.dir === "rtl" ? WB.table.toLtr : WB.table.toRtl}
          </Button>
          <span className="ms-2">{WB.table.fill}</span>
          {[undefined, ...TABLE_FILLS].map((fill) => (
            <button
              key={fill ?? "none"}
              type="button"
              aria-label={fill ?? WB.table.noFill}
              title={fill ? undefined : WB.table.noFill}
              onClick={() => fillSelected(fill)}
              className="h-7 w-7 rounded border border-line"
              // Dark ink on these light swatches in either theme.
              style={{ background: fill ?? "#ffffff", color: "#111111" }}
            >
              {fill ? "" : "∅"}
            </button>
          ))}
          <span className="ms-2" aria-hidden />
          <Button size="sm" variant="secondary" onClick={merge} disabled={!many}>{WB.table.merge}</Button>
          <Button size="sm" variant="secondary" onClick={() => setTable((t) => splitCell(t, focus[0], focus[1]))} disabled={!merged}>
            {WB.table.split}
          </Button>
        </div>
        <p className="text-xs text-ink-muted">{WB.table.mergeHint}</p>
        <p role="status" className="sr-only">
          {many ? WB.table.selection(range.bottom - range.top + 1, range.right - range.left + 1) : ""}
        </p>

        <div className="overflow-auto">
          <table dir={table.dir} className="border-collapse">
            <tbody>
              {table.rows.map((row, r) => (
                <tr key={r}>
                  {row.cells.map((value, c) =>
                    covered[r][c] ? null : (
                      <td
                        key={c}
                        rowSpan={value.span?.[0]}
                        colSpan={value.span?.[1]}
                        className={`border border-line p-0 ${many && selected(r, c) ? "outline outline-2 -outline-offset-2 outline-accent" : ""}`}
                        style={value.fill ? { background: value.fill, color: "#111111" } : undefined}
                      >
                        <textarea
                          aria-label={WB.table.cell(r + 1, c + 1)}
                          value={value.text}
                          rows={Math.max(1, value.span?.[0] ?? 1)}
                          dir="auto"
                          onMouseDown={(event) => {
                            // Shift + click stretches the selection instead of moving it.
                            if (!event.shiftKey) return;
                            event.preventDefault();
                            setCorner([r, c]);
                          }}
                          onKeyDown={(event) => {
                            // Ctrl+Shift+arrow stretches it from the keyboard (owner decision);
                            // left and right follow the screen, so they flip right to left.
                            const step = STEPS[event.key];
                            if (!step || !event.ctrlKey || !event.shiftKey) return;
                            event.preventDefault();
                            const [from, by] = [corner ?? focus, table.dir === "rtl" ? step : [step[0], -step[1]]];
                            setCorner([clamp(from[0] + by[0], rows - 1), clamp(from[1] + by[1], cols - 1)]);
                          }}
                          onFocus={() => {
                            setFocus([r, c]);
                            setCorner(null);
                          }}
                          onChange={(event) => cell(r, c, { text: event.target.value })}
                          className="block h-full min-h-10 w-full min-w-40 resize-y bg-transparent p-2 text-sm outline-none focus:ring-2 focus:ring-accent"
                        />
                      </td>
                    ),
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={saving}>{WB.table.cancel}</Button>
          <Button onClick={() => onSave(table)} loading={saving}>{WB.table.save}</Button>
        </div>
      </div>
    </div>
  );
}
