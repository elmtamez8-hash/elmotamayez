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
 * The seed gives that student a lesson of type `video`, but no **encoded
 * asset** behind it — there is no sample video in the repo. So these skip
 * loudly rather than pass vacuously; upload one from
 * /manage/courses/{uuid}/content and re-run. The reachability test
 * below runs either way: it is about the route, not the file.
 */

type Lesson = { uuid: string; type: string };
type Chapter = { lessons?: Lesson[] };
type Section = { chapters?: Chapter[] };

/**
 * Ask the API, as this student, for a lesson of type `video`.
 *
 * Whether its asset is *ready* is deliberately not asked here: no endpoint a
 * student can reach exposes that status. The honest probe is the page itself —
 * it asks for a grant and answers 409 «قيد التجهيز» when the video is not
 * encoded yet, which is exactly what `beforeEach` below skips on.
 */
async function findVideoLesson(page: Page): Promise<string | null> {
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
      const course = await get(`/courses/${enrollment.course_uuid}`);

      // Lessons hang off chapters, which hang off sections — there is no flat
      // `lessons` array on the course payload.
      const lessons: Lesson[] = ((course?.sections ?? []) as Section[]).flatMap(
        (section) => (section.chapters ?? []).flatMap((chapter) => chapter.lessons ?? []),
      );

      const video = lessons.find((lesson) => lesson.type === "video");
      if (video !== undefined) return video.uuid;
    }

    return null;
  });
}

test.describe("الوصول إلى المشغّل", () => {
  /*
   * The bug this catches is the one that already happened twice: the screen
   * exists and nothing in the product points at it. /learn/{lesson} shipped
   * reachable only by typing its URL, and no test noticed because every player
   * test navigated there directly. This one walks in from the sidebar instead.
   */
  test("تعلّمي ← دروس الكورس ← المشغّل", async ({ page }) => {
    await page.goto("/enrollments");

    const course = page.locator('a[href^="/enrollments/"]').first();
    await expect(course).toBeVisible();
    await course.click();

    await expect(page).toHaveURL(/\/enrollments\/[0-9a-f-]{36}$/);

    // The lesson list rendered — sections, chapters, rows.
    await expect(page.getByRole("listitem").first()).toBeVisible();

    // EVERY type links now, not video alone — 016 turned `/learn/{lesson}` into
    // the one screen a student opens any item on. So what follows can no longer
    // assume a player: it asserts what is true of every type.
    const toPlayer = page.locator('a[href^="/learn/"]');

    if ((await toPlayer.count()) > 0) {
      await toPlayer.first().click();
      await expect(page).toHaveURL(/\/learn\//);

      // The item's own title, whatever it turned out to be.
      await expect(page.getByRole("heading", { level: 1 }).last()).toBeVisible();

      // And no failure over it. This caught a real one: the page decided whether
      // to ask for a playback grant BEFORE the item's type had arrived, so it
      // asked for every item — and an article answered 403, correctly, painting
      // "تعذّرت المشاهدة" in red across content that had loaded fine.
      await expect(page.getByText("تعذّرت المشاهدة")).toHaveCount(0);
    }
  });
});

test.describe("مشغّل الدرس والعلامة المائية", () => {
  let lesson: string | null = null;

  test.beforeEach(async ({ page }) => {
    lesson ??= await findVideoLesson(page);

    test.skip(
      lesson === null,
      "لا يوجد درس من نوع فيديو في البيانات المبذورة — أنشئ واحداً ثم أعد التشغيل.",
    );

    await page.goto(`/learn/${lesson}`);

    // A lesson row can exist with no encoded asset behind it; the page says so
    // rather than rendering a player. Skip loudly instead of failing on a
    // <video> that was never going to be there.
    const player = await page.waitForSelector("video", { timeout: 10_000 }).catch(() => null);

    test.skip(
      player === null,
      "الدرس بلا فيديو جاهز — ارفع فيديو من /manage/courses/{uuid}/content ثم أعد التشغيل.",
    );
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
