import { readFileSync } from "node:fs";

import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

import { TEACHER_FILE, useTeacherAccount } from "./teacher-account";

/*
| Spec 039 — the teacher's whiteboard, end to end (T051 · T060 · T076).
|
| ⚠️ ONE PROJECT. The board is a desktop tool; six viewport × theme copies of a
| test that draws and saves would only race each other on the same rows.
*/

const API = "http://localhost:8000/api/v1";

// Each test draws, waits for real saves and reloads: well past the 30 s default
// when five run side by side (measured 22–28 s alone, timing out together).
test.describe.configure({ timeout: 90_000 });

function token(): string {
  return (JSON.parse(readFileSync(TEACHER_FILE, "utf8")) as { token: string }).token;
}

async function createBoard(request: APIRequestContext, title: string): Promise<string> {
  const response = await request.post(`${API}/boards`, {
    headers: { Authorization: `Bearer ${token()}`, Accept: "application/json" },
    data: { title },
  });
  expect(response.status()).toBe(201);
  return ((await response.json()) as { uuid: string }).uuid;
}

interface ServerPage {
  uuid: string;
  version: number;
  scene: string;
}

async function serverPages(request: APIRequestContext, board: string): Promise<ServerPage[]> {
  const response = await request.get(`${API}/boards/${board}`, {
    headers: { Authorization: `Bearer ${token()}`, Accept: "application/json" },
  });
  return ((await response.json()) as { pages: ServerPage[] }).pages;
}

const typesOf = (page: ServerPage) => (JSON.parse(page.scene) as { elements: { type: string }[] }).elements.map((e) => e.type);

/** Open a board and wait until this tab is the editor. */
async function openBoard(page: Page, board: string) {
  // The panel and the pages list start folded (2026-10-03): these tests use both, so both open.
  await page.addInitScript(() => {
    localStorage.setItem("whiteboard.panel.open", "1");
    localStorage.setItem("whiteboard.pages.open", "1");
  });
  await page.goto(`/whiteboard/${board}`);
  await expect(page.locator("[data-save-state]")).toBeVisible({ timeout: 30_000 });
}

async function drawRectangle(page: Page, at = { x: 700, y: 400 }) {
  await page.locator("canvas.interactive").click({ position: { x: 20, y: 600 } });
  await page.keyboard.press("r");
  await page.mouse.move(at.x, at.y);
  await page.mouse.down();
  await page.mouse.move(at.x + 160, at.y + 100, { steps: 5 });
  await page.mouse.up();
  await page.keyboard.press("Escape");
}

const indicator = (page: Page) => page.locator("[data-save-state]");

test.beforeEach(async ({ page }, info) => {
  test.skip(info.project.name !== "desktop-light", "the board runs on one project");
  await useTeacherAccount(page);
});

test.describe("النصّ العربي", () => {
  test("Arabic and mixed text is typed, saved as typed, and exported", async ({ page, request }) => {
    const board = await createBoard(request, "نصّ عربي");
    await openBoard(page, board);

    await page.locator("canvas.interactive").click({ position: { x: 20, y: 600 } });
    await page.keyboard.press("t");
    await page.mouse.click(700, 400);
    await page.keyboard.type("مرحباً Hello ١٢٣");
    await page.keyboard.press("Escape");

    await expect(indicator(page)).toHaveAttribute("data-save-state", "saved", { timeout: 15_000 });
    await expect
      .poll(async () => (await serverPages(request, board))[0].scene)
      .toContain("مرحباً Hello ١٢٣");

    const download = page.waitForEvent("download");
    await page.getByRole("button", { name: "PNG" }).click();
    expect((await download).suggestedFilename()).toMatch(/\.png$/);
  });
});

test.describe("الحفظ والاستعادة", () => {
  test("a network drop loses nothing: offline on the device, saved when it returns", async ({ page, context, request }) => {
    const board = await createBoard(request, "انقطاع الشبكة");
    await openBoard(page, board);

    await context.setOffline(true);
    await drawRectangle(page);
    await expect(indicator(page)).toHaveAttribute("data-save-state", "offline", { timeout: 15_000 });
    // The service worker must not send the board to /offline while the network is down.
    expect(new URL(page.url()).pathname).toBe(`/whiteboard/${board}`);

    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event("online")));
    await expect(indicator(page)).toHaveAttribute("data-save-state", "saved", { timeout: 20_000 });

    const [saved] = await serverPages(request, board);
    expect(saved.version).toBeGreaterThan(1);
    expect(typesOf(saved)).toContain("rectangle");
  });

  test("a second tab is read-only while the first edits", async ({ page, context, request }) => {
    const board = await createBoard(request, "تبويبان");
    await openBoard(page, board);

    const second = await context.newPage();
    await second.goto(`/whiteboard/${board}`);
    await expect(second.getByText("للقراءة فقط")).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText("للقراءة فقط")).toHaveCount(0);
    await second.close();
  });
});

test.describe("الصفحات", () => {
  test("ten pages, each reached in under a second, and an order that survives a reload", async ({ page, request }) => {
    const board = await createBoard(request, "عشر صفحات");
    await openBoard(page, board);

    const add = page.getByRole("button", { name: "صفحة جديدة" });
    for (let i = 2; i <= 10; i++) {
      await add.click();
      await expect(page.getByText(`${i.toLocaleString("ar-EG")} من ${i.toLocaleString("ar-EG")}`)).toBeVisible();
    }

    // Back to the first page, then forward page by page, timing each turn.
    await page.keyboard.press("Escape");
    for (let i = 0; i < 9; i++) await page.keyboard.press("PageUp");
    await expect(page.getByText("١ من ١٠")).toBeVisible();

    for (let i = 2; i <= 10; i++) {
      const started = Date.now();
      await page.keyboard.press("PageDown");
      await expect(page.getByText(`${i.toLocaleString("ar-EG")} من ١٠`)).toBeVisible();
      expect(Date.now() - started).toBeLessThan(1000);
    }

    // Move the first page down one place; the order is the server's after a reload.
    const before = (await serverPages(request, board)).map((p) => p.uuid);
    await page.getByRole("button", { name: "انقل الصفحة لأسفل" }).first().click();
    await expect.poll(async () => (await serverPages(request, board)).map((p) => p.uuid)).toEqual([before[1], before[0], ...before.slice(2)]);

    await page.reload();
    await expect(indicator(page)).toBeVisible({ timeout: 30_000 });
    expect((await serverPages(request, board)).map((p) => p.uuid)).toEqual([before[1], before[0], ...before.slice(2)]);
  });
});

test.describe("الاستعادة من الجهاز", () => {
  /*
  | ⚠️ THE NETWORK IS CUT, NOT ROUTED. A save held by `page.route()` is let through
  | by Playwright when the page goes away, so the server got the drawing and the
  | (identical) draft was rightly discarded — measured with a probe. Offline blocks
  | every request, the last-chance send on close included, so the device draft is
  | the only copy. The new tab carries the old tab's id, as a reload of the same
  | tab would, so it keeps the edit lock and the restored page can be saved.
  */
  test("a save that never arrived is offered back on reopening, and restoring it saves it", async ({ page, context, request }) => {
    const board = await createBoard(request, "استعادة");
    await openBoard(page, board);

    await context.setOffline(true);
    await drawRectangle(page);
    await page.waitForTimeout(3000); // the device draft is written within two seconds
    const tab = await page.evaluate(() => sessionStorage.getItem("whiteboard.tab"));
    await page.close();
    await context.setOffline(false);

    const reopened = await context.newPage();
    await useTeacherAccount(reopened);
    await reopened.addInitScript((id) => id && sessionStorage.setItem("whiteboard.tab", id), tab);
    await reopened.goto(`/whiteboard/${board}`);

    expect(typesOf((await serverPages(request, board))[0])).not.toContain("rectangle");
    const restore = reopened.getByRole("button", { name: "استعِد تغييراتي" });
    await expect(restore).toBeVisible({ timeout: 30_000 });
    await restore.click();

    await expect.poll(async () => typesOf((await serverPages(request, board))[0]), { timeout: 20_000 }).toContain("rectangle");
  });
});

/** The panel's tool groups are tiles now (2026-10-03): open one by its name. */
async function openTools(page: Page, group: string) {
  await page.getByRole("button", { name: group, exact: true }).click();
}

const liveTypes = (page: ServerPage) =>
  (JSON.parse(page.scene) as { elements: { type: string; isDeleted?: boolean; customData?: { kind?: string; latex?: string } }[] }).elements.filter((e) => !e.isDeleted);

test.describe("الاستيراد", () => {
  test("a three-page PDF becomes three pages, each with its picture, done in the browser", async ({ page, request }) => {
    const { PDFDocument, StandardFonts } = await import("pdf-lib");
    const pdf = await PDFDocument.create();
    const font = await pdf.embedFont(StandardFonts.Helvetica);
    // Each page a little shorter, so the pictures' shapes tell the order apart.
    for (let i = 1; i <= 3; i++) pdf.addPage([842, 695 - 100 * i]).drawText(`Page ${i}`, { x: 60, y: 300, size: 48, font });
    const bytes = Buffer.from(await pdf.save());

    const board = await createBoard(request, "استيراد PDF");
    await openBoard(page, board);
    await openTools(page, "استيراد");
    await page.locator('input[type="file"]').setInputFiles({ name: "lesson.pdf", mimeType: "application/pdf", buffer: bytes });

    // The blank first page, then the three imported ones — and the board shows the first of them.
    await expect(page.getByRole("status").filter({ hasText: "أُضيف ٣ صفحات" })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText("٢ من ٤")).toBeVisible();
    await expect.poll(async () => (await serverPages(request, board)).length, { timeout: 30_000 }).toBe(4);
    await expect
      .poll(async () => (await serverPages(request, board)).slice(1).map((p) => liveTypes(p).some((e) => e.type === "image")), { timeout: 30_000 })
      .toEqual([true, true, true]);

    // In order, and three different pictures — not one picture three times.
    type Picture = { type: string; fileId?: string; width: number; height: number };
    const pictures = (await serverPages(request, board)).slice(1).map((p) => (liveTypes(p) as Picture[]).find((e) => e.type === "image")!);
    expect(new Set(pictures.map((e) => e.fileId)).size).toBe(3);
    const shapes = pictures.map((e) => e.height / e.width);
    expect(shapes[0]).toBeGreaterThan(shapes[1]);
    expect(shapes[1]).toBeGreaterThan(shapes[2]);
  });
});

test.describe("القلم السحري والآلة الحاسبة", () => {
  test("a circle drawn with the magic pen is saved as a clean ellipse", async ({ page, request }) => {
    const board = await createBoard(request, "القلم السحري");
    await openBoard(page, board);
    await openTools(page, "أدوات");
    await page.getByRole("button", { name: "قلم سحري ✦" }).click();

    const at = { x: 760, y: 420 };
    await page.mouse.move(at.x + 120, at.y);
    await page.mouse.down();
    for (let i = 1; i <= 48; i++) {
      const t = (i / 48) * Math.PI * 2;
      await page.mouse.move(at.x + 120 * Math.cos(t), at.y + 80 * Math.sin(t));
    }
    await page.mouse.up();

    await expect
      .poll(async () => liveTypes((await serverPages(request, board))[0]).map((e) => e.type).sort(), { timeout: 30_000 })
      .toEqual(["ellipse", "frame"]);
  });

  test("the calculator works a sum out and puts «question = answer» on the board", async ({ page, request }) => {
    const board = await createBoard(request, "الآلة الحاسبة");
    await openBoard(page, board);
    await openTools(page, "أدوات");
    await page.getByRole("button", { name: "آلة حاسبة" }).click();

    const calc = page.locator('[data-effect="calculator"]');
    await expect(calc.locator("math-field")).toHaveCount(2, { timeout: 30_000 });
    for (const key of ["1", "▭⁄▭", "2", "▶", "+", "1", "▭⁄▭", "3"]) await calc.getByRole("button", { name: key, exact: true }).click();
    await calc.getByRole("button", { name: "=", exact: true }).click();
    await expect.poll(() => calc.locator("math-field").nth(1).evaluate((el) => (el as HTMLElement & { value: string }).value), { timeout: 30_000 }).toBe(String.raw`\frac{5}{6}`);

    await calc.getByRole("button", { name: "حطّها على السبّورة" }).click();
    await expect
      .poll(async () => liveTypes((await serverPages(request, board))[0]).find((e) => e.customData?.kind === "math")?.customData?.latex, { timeout: 30_000 })
      .toBe(String.raw`\frac12+\frac13=\frac{5}{6}`);
  });
});

test.describe("الجدول", () => {
  test("cells merged in the editor are saved as one cell and drawn as one", async ({ page, request }) => {
    const board = await createBoard(request, "جدول مدموج");
    await openBoard(page, board);
    await openTools(page, "أدوات");
    await page.getByRole("button", { name: "إدراج جدول" }).click();

    const editor = page.getByRole("dialog", { name: "الجدول" });
    await editor.getByLabel("الصف ١، العمود ١").fill("العنوان");
    await editor.getByLabel("الصف ١، العمود ٣").click({ modifiers: ["Shift"] });
    await editor.getByRole("button", { name: "دمج الخلايا" }).click();
    await editor.getByLabel("الصف ٢، العمود ١").fill("س");
    await editor.getByRole("button", { name: "حفظ" }).click();
    await expect(editor).toBeHidden({ timeout: 30_000 });

    type Table = { customData?: { kind?: string; rows?: { cells: { text: string; span?: [number, number] }[] }[] } };
    await expect
      .poll(async () => (liveTypes((await serverPages(request, board))[0]) as Table[]).find((e) => e.customData?.kind === "table")?.customData?.rows?.[0].cells[0], { timeout: 30_000 })
      .toEqual({ text: "العنوان", span: [1, 3] });
    if (process.env.E2E_SHOT) await page.screenshot({ path: process.env.E2E_SHOT });
  });
});

test.describe("رسم الدوال", () => {
  test("a graph is drawn from its functions and saved with them", async ({ page, request }) => {
    const board = await createBoard(request, "رسم دالة");
    await openBoard(page, board);
    await openTools(page, "أدوات");
    await page.getByRole("button", { name: "رسم دالة" }).click();

    const editor = page.getByRole("dialog", { name: "رسم الدوال" });
    await editor.getByRole("button", { name: "إضافة دالة" }).click();
    await editor.getByLabel("الدالة ٢").fill("sin(x)");
    await expect(editor.getByRole("img", { name: "معاينة الرسم" })).toBeVisible({ timeout: 30_000 });
    if (process.env.E2E_SHOT) await page.screenshot({ path: `${process.env.E2E_SHOT}-editor.png` });
    await editor.getByRole("button", { name: "إدراج" }).click();
    await expect(editor).toBeHidden({ timeout: 30_000 });

    type Graph = { customData?: { kind?: string; functions?: { expr: string }[] } };
    await expect
      .poll(async () => (liveTypes((await serverPages(request, board))[0]) as Graph[]).find((e) => e.customData?.kind === "graph")?.customData?.functions?.map((f) => f.expr), { timeout: 30_000 })
      .toEqual(["x^2 - 3", "sin(x)"]);
    if (process.env.E2E_SHOT) await page.screenshot({ path: `${process.env.E2E_SHOT}-board.png` });
  });
});
