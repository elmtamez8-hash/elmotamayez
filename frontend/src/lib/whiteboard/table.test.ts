import { describe, expect, it } from "vitest";

import { blankTable, normalise, parseClipboardTable } from "@/lib/whiteboard/table";

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

  it("gives every column a width", () => {
    expect(normalise({ ...blankTable(1, 1), colWidths: [] }).colWidths).toEqual([240]);
  });
});
