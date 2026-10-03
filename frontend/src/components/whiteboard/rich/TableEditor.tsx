"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { normalise, TABLE_FILLS, type TableData } from "@/lib/whiteboard/table";
import { WB } from "@/lib/whiteboard/strings";

/**
 * The table's cells, edited (story 6): rows and columns added and removed, a
 * cell's fill, and its direction. Right to left by default — the first column
 * is the right-hand one (US6-1). Saving hands back the cells; the board draws
 * the picture and puts it in place.
 */
export function TableEditor({ initial, onSave, onClose }: { initial: TableData; onSave: (data: TableData) => void; onClose: () => void }) {
  const [table, setTable] = useState(() => normalise(initial));
  const [focus, setFocus] = useState<[number, number]>([0, 0]);
  const rows = table.rows.length;
  const cols = table.colWidths.length;

  const cell = (r: number, c: number, change: Partial<TableData["rows"][number]["cells"][number]>) =>
    setTable((t) => ({
      ...t,
      rows: t.rows.map((row, ri) => (ri !== r ? row : { cells: row.cells.map((x, ci) => (ci === c ? { ...x, ...change } : x)) })),
    }));

  const addRow = () => setTable((t) => normalise({ ...t, rows: [...t.rows, { cells: [] }] }));
  const removeRow = () => rows > 1 && setTable((t) => ({ ...t, rows: t.rows.slice(0, -1) }));
  const addCol = () => setTable((t) => normalise({ ...t, colWidths: [...t.colWidths, 240] }));
  const removeCol = () =>
    cols > 1 && setTable((t) => ({ ...t, colWidths: t.colWidths.slice(0, -1), rows: t.rows.map((row) => ({ cells: row.cells.slice(0, -1) })) }));

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
              onClick={() => cell(focus[0], focus[1], { fill })}
              className="h-7 w-7 rounded border border-line"
              style={{ background: fill ?? "#ffffff" }}
            >
              {fill ? "" : "∅"}
            </button>
          ))}
        </div>

        <div className="overflow-auto">
          <table dir={table.dir} className="border-collapse">
            <tbody>
              {table.rows.map((row, r) => (
                <tr key={r}>
                  {row.cells.map((value, c) => (
                    <td key={c} className="border border-line p-0" style={{ background: value.fill }}>
                      <textarea
                        aria-label={WB.table.cell(r + 1, c + 1)}
                        value={value.text}
                        rows={1}
                        dir="auto"
                        onFocus={() => setFocus([r, c])}
                        onChange={(event) => cell(r, c, { text: event.target.value })}
                        className="block min-h-10 w-40 resize-y bg-transparent p-2 text-sm outline-none focus:ring-2 focus:ring-accent"
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{WB.table.cancel}</Button>
          <Button onClick={() => onSave(table)}>{WB.table.save}</Button>
        </div>
      </div>
    </div>
  );
}
