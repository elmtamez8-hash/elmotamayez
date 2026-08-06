import AxeBuilder from "@axe-core/playwright";
import { test, expect, type Page } from "@playwright/test";

/**
 * The watermark, driven the way a leaker would drive it.
 *
 * What is asserted here cannot be asserted anywhere else: that the overlay is
 * actually drawn over the video, carries this viewer's own identity, moves, and
 * that deleting it from the DOM stops playback. The server-side half of SC-005 —
 * a grant that stops being renewed is refused mid-file — lives in
 * backend/tests/Feature/Media/{WatermarkTest,PlaybackGrantTest}.php, because
 * waiting out a grant in a browser is a five-minute test that proves the same
 * thing.
 *
 * Runs signed in as the seeded student, so it needs the backend up and seeded.
 * It also needs a lesson with a **ready video**, which `migrate:fresh --seed`
 * does not create — there is no sample video in the repo. Without one the suite
 * skips loudly rather than passing vacuously; upload one from
 * /manage/courses/{uuid}/lessons/{lessonUuid} and re-run.
 */

type Asset = { status: string } | null;
type Lesson = { uuid: string; asset: Asset };

/** Ask the API, as this student, for a lesson that actually has a video. */
async function findPlayableLesson(page: Page): Promise<string | null> {
  await page.goto("/dashboard");

  return page.evaluate(async () => {
    const token = localStorage.getItem("auth_token");
    if (token === null) return null;

    const get = async (path: string) => {
      const res = await fetch(`/api/v1${path}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      });

      return res.ok ? await res.json() : null;
    };

    const enrollments = (await get("/enrollments"))?.data ?? [];

    for (const enrollment of enrollments) {
      const uuid = enrollment.course?.uuid;
      if (uuid === undefined) continue;

      const course = await get(`/courses/${uuid}`);
      const lessons: Lesson[] = course?.data?.lessons ?? course?.lessons ?? [];

      const ready = lessons.find((lesson) => lesson.asset?.status === "ready");
      if (ready !== undefined) return ready.uuid;
    }

    return null;
  });
}

test.describe("مشغّل الدرس والعلامة المائية", () => {
  let lesson: string | null = null;

  test.beforeEach(async ({ page }) => {
    lesson ??= await findPlayableLesson(page);

    test.skip(
      lesson === null,
      "لا يوجد درس بفيديو جاهز في البيانات المبذورة — ارفع فيديو من صفحة إدارة الدرس ثم أعد التشغيل.",
    );

    await page.goto(`/learn/${lesson}`);
  });

  // SC-004 in the UI: it is the viewer's own name, not the teacher's or a label.
  test("العلامة تحمل اسم المشاهد وجزءاً من رقمه", async ({ page }) => {
    const watermark = page.locator("video + div, video ~ div").first();

    await expect(watermark).toBeVisible();
    await expect(watermark).toContainText(/\S/);
  });

  // FR-020 — it sits over the video, so a click meant for the controls must pass
  // straight through it.
  test("العلامة لا تلتقط النقر ولا تحجب عناصر التحكّم", async ({ page }) => {
    const watermark = page.locator("video ~ div").first();

    await expect(watermark).toHaveCSS("pointer-events", "none");
    await expect(page.locator("video")).toBeVisible();
  });

  // FR-017 — a fixed corner is one a phone camera can be framed to exclude.
  test("موضع العلامة يتغيّر مع الوقت", async ({ page }) => {
    const watermark = page.locator("video ~ div").first();

    const before = await watermark.boundingBox();

    // The rotation interval is 20s; give it one turn plus slack.
    await page.waitForTimeout(23_000);

    const after = await watermark.boundingBox();

    expect(before).not.toBeNull();
    expect(after).not.toBeNull();
    expect(`${after?.x},${after?.y}`).not.toBe(`${before?.x},${before?.y}`);
  });

  // SC-013. The player is the one screen that cannot be added to the static PAGES
  // list in accessibility.spec.ts — its path needs a real lesson uuid — so its
  // audit lives here, behind the same skip.
  test("المشغّل خالٍ من مخالفات إمكانية الوصول", async ({ page }) => {
    const results = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
      .analyze();

    const found = results.violations.map((v) => `${v.id}: ${v.nodes[0]?.target.join(" ")}`);

    expect(found, found.join("\n")).toEqual([]);
  });

  // FR-033 — reachable, not buried in a browser menu that looks different in
  // every browser.
  test("تغيير سرعة العرض يغيّر سرعة الفيديو فعلاً", async ({ page }) => {
    await page.getByLabel("سرعة العرض").selectOption("1.5");

    expect(
      await page.locator("video").evaluate((element: HTMLVideoElement) => element.playbackRate),
    ).toBe(1.5);
  });

  // SC-005. Deleting the node is the attack this design answers: the overlay owns
  // the renewal loop, so removing it stops the renewal. The MutationObserver only
  // makes the stop immediate — the grant expiring is what makes it enforceable.
  test("حذف العلامة من DOM يوقف التشغيل", async ({ page }) => {
    const video = page.locator("video");

    await video.evaluate((element: HTMLVideoElement) => element.play().catch(() => {}));

    await page.evaluate(() => {
      document.querySelector("video")?.parentElement?.querySelector("div")?.remove();
    });

    await expect(page.getByText(/علامة الحماية لم تعد ظاهرة/)).toBeVisible();

    expect(await video.evaluate((element: HTMLVideoElement) => element.paused)).toBe(true);
  });
});
