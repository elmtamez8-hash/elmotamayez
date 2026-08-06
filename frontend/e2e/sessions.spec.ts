import { test, expect } from "@playwright/test";

/**
 * The two routes into the sessions feature, walked the way a person walks them.
 *
 * Deliberately entered FROM the sidebar rather than by navigating to the URL.
 * A spec that calls page.goto("/schedule") passes just as happily when nothing
 * in the product links to /schedule — which is how spec 004's whole player
 * shipped reachable only by typing its address.
 *
 * Runs signed in as the seeded student (see auth.setup.ts), so it needs the
 * backend up and seeded.
 */

test.describe("الوصول إلى الحصص", () => {
  test("الشريط الجانبي ← جدولي", async ({ page }) => {
    await page.goto("/dashboard");

    await page.getByRole("link", { name: "جدولي" }).click();

    await expect(page).toHaveURL(/\/schedule$/);
    // level: 2 — the shell header repeats the page title as an h1, so an
    // unqualified heading matcher resolves to two elements.
    await expect(page.getByRole("heading", { name: "جدولي", level: 2 })).toBeVisible();

    // Either upcoming sessions or a stated empty state — never a raw error.
    // `.first()`: with nothing booked BOTH empty states are on screen at once,
    // the countdown's and the list's, so an unqualified match resolves to two.
    await expect(
      page.getByText(/لا حصص قادمة في جدولك\.|لا حصص محجوزة|حصتك القادمة/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/Request failed|Server Error|undefined/);
  });

  test("الشريط الجانبي ← حصصي ← التوليد ظاهر", async ({ page }) => {
    await page.goto("/dashboard");

    await page.getByRole("link", { name: "حصصي" }).click();

    await expect(page).toHaveURL(/\/manage\/sessions$/);
    await expect(page.getByRole("heading", { name: "حصصي", level: 2 })).toBeVisible();

    // The generator is the only producer for the availability the marketplace
    // has published since spec 001, so its absence is the feature missing.
    await expect(page.getByRole("heading", { name: /توليد حصص/ })).toBeVisible();
    await expect(page.getByRole("button", { name: "توليد" })).toBeDisabled();
  });

  // The whole walk: sidebar → my schedule → a session → the room. The seed
  // books the demo student a seat (DemoDataSeeder) precisely so this does not
  // stop at an empty state and report green.
  test("جدولي ← حصة ← الغرفة", async ({ page }) => {
    await page.goto("/dashboard");
    await page.getByRole("link", { name: "جدولي" }).click();

    // The list is fetched client-side, so counting before it settles counts
    // zero and skips a test that would have passed. Wait for either outcome
    // first — that is the difference between "no seat" and "not loaded yet".
    await expect(page.getByText(/لا حصص محجوزة|حصة تجريبية/).first()).toBeVisible();

    const session = page.getByRole("link", { name: /حصة تجريبية/ }).first();

    test.skip(
      (await session.count()) === 0,
      "لا حصة محجوزة للطالب المبذور — نفّذ php artisan migrate:fresh --seed ثم أعد التشغيل.",
    );

    // On the student's own timetable the title IS the way in: the room is the
    // only thing they can do with a booked hour.
    await session.click();

    await expect(page).toHaveURL(/\/sessions\/[0-9a-f-]+\/room$/);
    await expect(page.getByRole("heading", { name: "غرفة الحصة" })).toBeVisible();

    // A ticket or a stated refusal — the room outside its window is a refusal
    // with a reason, never a blank page or a raw error.
    await expect(
      page.getByText(/الغرفة مفتوحة|تعذّر الدخول|جارٍ التحضير…/).first(),
    ).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/Request failed|undefined/);
  });

  // And the teacher's half: sidebar → my sessions → a session → the register.
  test("حصصي ← حصة ← كشف الحضور", async ({ page }) => {
    await page.goto("/dashboard");
    await page.getByRole("link", { name: "حصصي" }).click();

    await expect(page.getByText(/لا حصص بعد|حصة تجريبية/).first()).toBeVisible();

    const card = page.getByRole("link", { name: /حصة تجريبية/ }).first();

    test.skip((await card.count()) === 0, "لا حصص مبذورة — أعد تنفيذ migrate:fresh --seed.");

    await card.click();

    await expect(page).toHaveURL(/\/manage\/sessions\/[0-9a-f-]+$/);
    await expect(page.getByRole("heading", { name: "كشف الحضور" })).toBeVisible();
  });

  // FR-055 · the empty state is an answer, not a failure.
  test("العدّاد يعرض حالة مفهومة بلا حصص", async ({ page }) => {
    await page.goto("/schedule");

    await expect(page.getByText("جارٍ التحميل…")).toBeHidden();
    await expect(page.locator("body")).not.toContainText(/NaN|Invalid Date/);
  });

  // Freezing has no nav entry on purpose — it is an action on the calendar, not
  // a section. Which makes this link the ONLY way in, so the walk is the test.
  test("حصصي ← فترات التجميد", async ({ page }) => {
    await page.goto("/manage/sessions");

    await page.getByRole("link", { name: "فترات التجميد" }).click();

    await expect(page).toHaveURL(/\/manage\/freeze$/);
    await expect(page.getByRole("heading", { name: "فترات التجميد", level: 2 })).toBeVisible();
    await expect(page.getByRole("button", { name: "تجميد الفترة" })).toBeDisabled();

    // And back the way we came.
    await page.getByRole("link", { name: "← حصصي" }).click();
    await expect(page).toHaveURL(/\/manage\/sessions$/);
  });
});
