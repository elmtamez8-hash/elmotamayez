import { describe, expect, it } from "vitest";

import { blankTable, cellRange, coveredBy, mergeCells, normalise, parseClipboardTable, splitCell, type TableData } from "@/lib/whiteboard/table";

// What Excel and Google Sheets put on the clipboard, trimmed to the parts read.
const EXCEL = `<html><body><!--StartFragment--><table border=0><tr height=20><td>الاسم</td><td>الدرجة</td></tr><tr height=20><td>أحمد</td><td align=right>18</td></tr></table><!--EndFragment--></body></html>`;
const SHEETS = `<meta charset="utf-8"><google-sheets-html-origin><table dir="rtl"><tbody><tr><td>س</td><td>ص</td><td>ع</td></tr><tr><td>1</td><td>2</td></tr></tbody></table>`;

describe("parseClipboardTable", () => {
  it("reads Excel's HTML table, cell text only", () => {
    const table = parseClipboardTable(EXCEL, "الاسم\tالدرجة\nأحمد\t18\n");
    expect(table?.rows.map((row) => row.cells.map((cell) => cell.text))).toEqual([
      ["الاسم", "الدرجة"],
      ["أحمد", "18"],
    ]);
    expect(table?.dir).toBe("rtl");
  });

  it("reads Google Sheets' table and evens out a short row", () => {
    const table = parseClipboardTable(SHEETS, "");
    expect(table?.rows[1].cells.map((cell) => cell.text)).toEqual(["1", "2", ""]);
    expect(table?.colWidths).toHaveLength(3);
  });

  it("falls back to tab-separated text, and leaves a single cell or plain text alone", () => {
    expect(parseClipboardTable("", "a\tb\nc\td")?.rows).toHaveLength(2);
    expect(parseClipboardTable("", "just a sentence")).toBeNull();
    expect(parseClipboardTable("<table><tr><td>one</td></tr></table>", "one")).toBeNull();
  });

  it("keeps Excel's merged cells merged, and the columns after them in place", () => {
    const html = `<html xmlns:x="urn:schemas-microsoft-com:office:excel"><table>
      <tr><td colspan=2>العنوان</td><td>ج</td></tr>
      <tr><td rowspan=2>أ</td><td>1</td><td>2</td></tr>
      <tr><td>3</td><td>4</td></tr></table></html>`;
    const table = parseClipboardTable(html, "");
    expect(table?.rows.map((row) => row.cells.map((cell) => cell.text))).toEqual([
      ["العنوان", "", "ج"],
      ["أ", "1", "2"],
      ["", "3", "4"],
    ]);
    expect(table?.rows[0].cells[0].span).toEqual([1, 2]);
    expect(table?.rows[1].cells[0].span).toEqual([2, 1]);
  });

  it("a row a merge covers whole stays a row, so the data under it keeps its columns", () => {
    const html = `<html xmlns:x="urn:schemas-microsoft-com:office:excel"><table>
      <tr><td rowspan=2 colspan=2>العنوان</td></tr>
      <tr></tr>
      <tr><td>x</td><td>y</td></tr></table></html>`;
    expect(parseClipboardTable(html, "")?.rows.map((row) => row.cells.map((cell) => cell.text))).toEqual([
      ["العنوان", ""],
      ["", ""],
      ["x", "y"],
    ]);
  });

  it("never turns clipboard markup into markup", () => {
    const table = parseClipboardTable("<google-sheets-html-origin><table><tr><td><img src=x onerror=alert(1)>a</td><td><b>b</b></td></tr></table>", "");
    expect(table?.rows[0].cells.map((cell) => cell.text)).toEqual(["a", "b"]);
  });
});

describe("parseClipboardTable leaves ordinary pastes alone", () => {
  it("a layout table in an email, a Word list, indented code", () => {
    expect(parseClipboardTable("<table><tr><td>مرحباً</td><td>بكم</td></tr></table>", "مرحباً بكم")).toBeNull();
    expect(parseClipboardTable("<ul><li>بند</li></ul>", "•\tبند\n•\tآخر")).toBeNull();
    expect(parseClipboardTable("", "\tfoo()")).toBeNull();
  });

  it("cuts a huge sheet to what a stream can show", () => {
    const text = Array.from({ length: 80 }, () => Array.from({ length: 20 }, () => "x").join("\t")).join("\n");
    const table = parseClipboardTable("", text);
    expect(table?.rows).toHaveLength(50);
    expect(table?.colWidths).toHaveLength(12);
  });
});

describe("normalise", () => {
  it("keeps only the board's own fills", () => {
    const table = normalise({ ...blankTable(1, 2), rows: [{ cells: [{ text: "a", fill: "url(https://evil)" }, { text: "b", fill: "#fff3b0" }] }] });
    expect(table.rows[0].cells.map((cell) => cell.fill)).toEqual([undefined, "#fff3b0"]);
  });

  it("cuts a merge to the grid and off another merge, and drops a broken one", () => {
    const t = blankTable(3, 3);
    t.rows[0].cells[0] = { text: "a", span: [2, 9] };
    t.rows[1].cells[1] = { text: "b", span: [2, 2] }; // under the first merge: no merge of its own
    t.rows[2].cells[0] = { text: "c", span: ["x", -1] as unknown as [number, number] };
    const n = normalise(t);
    expect(n.rows[0].cells[0].span).toEqual([2, 3]);
    expect(n.rows[1].cells[1].span).toBeUndefined();
    expect(n.rows[2].cells[0].span).toBeUndefined();
  });

  it("gives every column a width", () => {
    expect(normalise({ ...blankTable(1, 1), colWidths: [] }).colWidths).toEqual([240]);
  });
});

const texts = (t: TableData) => t.rows.map((row) => row.cells.map((cell) => cell.text));

describe("merging and splitting cells", () => {
  it("merges a range into its first cell, keeping every text a line each", () => {
    const t = blankTable(2, 3);
    t.rows[0].cells[0].text = "س";
    t.rows[1].cells[1].text = "ص";
    const m = mergeCells(t, cellRange(t, [0, 0], [1, 1]));
    expect(m.rows[0].cells[0]).toEqual({ text: "س\nص", span: [2, 2] });
    expect(texts(m)).toEqual([["س\nص", "", ""], ["", "", ""]]);
    expect(coveredBy(m)[1][1]).toEqual([0, 0]);
    expect(coveredBy(m)[1][2]).toBeNull();
  });

  it("grows a selection to take in a merged cell it cuts through", () => {
    const m = mergeCells(blankTable(3, 3), { top: 0, left: 1, bottom: 1, right: 2 });
    expect(cellRange(m, [1, 0], [1, 1])).toEqual({ top: 0, left: 0, bottom: 1, right: 2 });
  });

  it("splits back to single cells, the text in the first", () => {
    const t = blankTable(1, 2);
    t.rows[0].cells[1].text = "ب";
    const s = splitCell(mergeCells(t, { top: 0, left: 0, bottom: 0, right: 1 }), 0, 0);
    expect(s.rows[0].cells).toEqual([{ text: "ب" }, { text: "" }]);
  });

  it("a removed column cuts the merge short", () => {
    const m = mergeCells(blankTable(1, 3), { top: 0, left: 0, bottom: 0, right: 2 });
    const cut = normalise({ ...m, colWidths: m.colWidths.slice(0, -1), rows: m.rows.map((row) => ({ cells: row.cells.slice(0, -1) })) });
    expect(cut.rows[0].cells[0].span).toEqual([1, 2]);
  });
});
