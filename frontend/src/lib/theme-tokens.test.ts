import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
| توكنٌ لا وجودَ له لا يُخطئُ — يرسمُ لا شيءَ ويمرُّ.
|
| Tailwind v4 يقرأُ الألوانَ من `@theme`، فصنفٌ يسمّي توكناً غيرَ معرَّفٍ لا يُنتِجُ
| قاعدةً أصلاً: لا خطأَ بناء، ولا تحذير، ولا فرقَ في أيِّ لقطةِ شاشة — فقط نقطةٌ
| شفّافةٌ مكانَ العلامةِ الخضراء. حدث مرّتَين: `bg-success-soft` في `PracticeRunner`
| ثمّ `bg-success`/`border-success`/`text-success-ink` في غرفةِ البثّ ولوحةِ
| المشاركين، وكِلاهما كان له اختبارٌ أخضرُ يقيسُ `aria-label` فوقَ علامةٍ لا تُرى.
|
| ولهذا يقرأُ هذا الملفُّ `globals.css` بدلاً من أن يحملَ قائمةً: قائمةٌ مكتوبةٌ هنا
| هي إجابةٌ ثانيةٌ لسؤالٍ واحد، وتشيخُ في أوّلِ توكنٍ يُضاف.
*/

const SRC = join(process.cwd(), "src");

/** The colour names `@theme` actually defines. */
function definedTokens(): Set<string> {
  const css = readFileSync(join(SRC, "app", "globals.css"), "utf8");

  return new Set([...css.matchAll(/--color-([a-z0-9-]+)\s*:/g)].map((m) => m[1]));
}

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) return sourceFiles(path);

    return /\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name) ? [path] : [];
  });
}

/*
  Only the semantic families this product invents. Tailwind's own palette
  (`white`, `black`, `red-500`) and its keywords (`transparent`, `current`) are
  real classes that need no token, so a blanket scan would be noise — and noise
  is how a guard gets deleted. These four names are the ones somebody reaches for
  because they SOUND like our tokens.
*/
/*
  ⚠️ `divide` IS IN THE PREFIX LIST AND WAS NOT, which let one straight through:
  spec 011's referrals page was written with `divide-border`, and there is no
  `border` token — the divider is `line`. Tailwind v4 emits no rule for a token
  it has never seen, so the list simply had no lines between its rows, with no
  error, no warning and nothing in a snapshot. It was caught by reading
  `globals.css` by hand, which is not a guard.

  ⚠️ AND `border` IS NOW A NAME FOR THE SAME REASON. `divide-border` and
  `bg-border` both name a token that does not exist; the plain utilities
  (`border`, `border-t`, `border-2`) do NOT match, because the pattern needs a
  prefix AND a name after it.
*/
/*
  ⚠️ AND `warning` IS ON THE LIST NOW, CAUGHT ONE EDIT BEFORE IT SHIPPED. There
  is no `warning` token either — the warning TONE is `bg-accent/20 text-ink`
  (`TONE_CLASSES`) — and `text-warning-ink` on the groups screen would have been
  the fifth invisible mark in this family. The name is exactly the kind this list
  exists for: `Alert` and `Badge` both take `tone="warning"`, so it reads like a
  token and is not one.
*/
const TEMPTING =
  /\b(?:bg|text|border|ring|fill|from|to|divide)-(surface-muted|success|error|warning|info|muted|border)(?:-[a-z]+)?\b/g;

/**
 * Comments out, because a comment naming a dead class is the FIX being written
 * down, not the defect. `PracticeRunner` and both files below carry exactly that
 * note; scanning them raw makes the guard fire on its own documentation, and a
 * guard that cries over its own fix is one somebody deletes.
 */
function code(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
}

describe("theme tokens", () => {
  it("has no colour class naming a token @theme never defined", () => {
    const tokens = definedTokens();
    const offences: string[] = [];

    for (const file of sourceFiles(SRC)) {
      for (const match of code(readFileSync(file, "utf8")).matchAll(TEMPTING)) {
        // The full suffix, e.g. `success-ink` — a family may exist while the
        // shade a component asked for does not.
        const name = match[0].slice(match[0].indexOf("-") + 1);

        if (!tokens.has(name)) {
          offences.push(`${file.slice(SRC.length + 1)}: ${match[0]}`);
        }
      }
    }

    expect(offences).toEqual([]);
    /*
      ⚠️ مهلةٌ صريحةٌ لأنّ هذا ليس اختبارَ وحدة: إنّه مشيٌ متزامنٌ على شجرةِ `src`
      كاملةً، قراءةً ملفّاً ملفّاً — فكلفتُه تنمو مع المشروعِ لا مع ما يقيسه.
      المهلةُ الافتراضيّةُ خمسُ ثوانٍ، وقد قيسَ في 2026-09-03 عند 5.8s تحتَ ضغطِ
      تشغيلةٍ متوازيةٍ من ٥٩ ملفّاً — بينما يفرغُ وحدَه في 300ms.

      ⚠️ ورسوبُه بمهلةٍ ليس رسوباً بمخالفةِ لون، وهما يُقرآنِ متشابهَين في السجلّ.
      وهذه هي المشكلةُ: حارسٌ يرسبُ عشوائيّاً هو حارسٌ يُعطَّلُ بعدَ ثالثِ مرّة،
      ومعه العطلُ الذي شُحن أربعَ مرّاتٍ في هذه الشجرة.
    */
  }, 30_000);

  it("never paints TEXT with a fill hue whose -ink sibling exists for exactly that", () => {
    /*
      ⚠️ رمزٌ **معرَّفٌ** في المكانِ الخطأ — وهو عطلٌ لا يراه الحارسُ فوقَه.
      `text-primary` صنفٌ صحيحٌ يُنتِجُ قاعدةً حقيقيّة، فيمرُّ من فحصِ «توكنٌ لا
      وجودَ له» سالماً؛ لكنّ `--color-primary` مارونٌ **لا يفتحُ في السمةِ
      الداكنة** (وتعليقُه في `globals.css` يقولُ ذلك حرفيّاً: «Maroon reaches
      1.7:1 on #191315 — as TEXT it is unreadable in the dark»)، فالنصُّ
      المكتوبُ به غيرُ مقروءٍ عند ١٫٧:١. وقد شحنَ في **عشرينَ** موضعاً — منها
      نصُّ السؤالِ في بنكِ الأسئلة، ورسائلُ الخطأِ الحمراءُ في ثلاثِ شاشات،
      وروابطُ التصفّحِ في المدوّنةِ العامّة — واكتشفَه مدرّسٌ ينظرُ إلى شاشتِه،
      لا اختبار.

      ⚠️ والقاعدةُ **مشتقّةٌ لا مكتوبة**: كلُّ رمزٍ له شقيقٌ `-ink` هو حشوٌ
      بالتعريف، لأنّ الشقيقَ لم يُخلَقْ إلّا لأنّ الأصلَ يرسبُ نصّاً. فرمزٌ
      يُضافُ غداً بشقيقِه يدخلُ هذا الحارسَ من تلقائِه — وقائمةٌ مكتوبةٌ هنا
      كانت لتشيخَ في أوّلِ واحد.

      ⚠️ ولا يشملُ `accent`: شقيقُه `-foreground` لا `-ink`، وهو لونُ ما يُكتَبُ
      **فوقَ** الحشوِ لا بديلٌ عنه — فلا مقابلَ أعرضُه، والحارسُ الذي يمنعُ بلا
      بديلٍ يُلتَفُّ عليه.
    */
    const fills = [...definedTokens()]
      .filter((token) => token.endsWith("-ink"))
      .map((token) => token.slice(0, -"-ink".length));

    // The premise: a broken parse would leave this empty and the scan below
    // would be green by matching nothing at all.
    expect(fills).toContain("primary");
    expect(fills).toContain("danger");

    // `(?!-)` so `text-primary-ink` and `text-primary-soft` are untouched: the
    // offence is the BARE hue, and a pattern without it bans its own fix.
    const banned = new RegExp(String.raw`\btext-(${fills.join("|")})\b(?!-)`, "g");
    const offences: string[] = [];

    for (const file of sourceFiles(SRC)) {
      for (const match of code(readFileSync(file, "utf8")).matchAll(banned)) {
        offences.push(`${file.slice(SRC.length + 1)}: ${match[0]} — use ${match[0]}-ink`);
      }
    }

    expect(offences).toEqual([]);
  }, 30_000);

  it("declares color-scheme for both themes, so the browser's own widgets are not painted light-on-dark", () => {
    /*
      ⚠️ هذا ليس عن رمزٍ لونيّ. `@theme` يحكمُ ما نرسمُه نحن، ولا يقولُ شيئاً عن
      الأدواتِ التي يرسمُها المتصفّحُ بنفسِه: زرُّ التقويمِ في `datetime-local`،
      ونافذتُه، وقائمةُ `select`، وأسهمُ العددِ، وشريطُ التمرير. `color-scheme`
      هي القناةُ الوحيدةُ إليها.

      وبغيابِها كانت أيقونةُ التاريخِ سوداءَ على سطحٍ داكن — موجودةً وقابلةً
      للتركيزِ وغيرَ مرئيّة، وهو الشكلُ نفسُه الذي شحنَ أربعَ مرّاتٍ في هذه
      الشجرةِ تحتَ اسمِ «رمزٌ غيرُ معرَّفٍ لا يرسمُ شيئاً»: لا خطأ، ولا لقطةٌ
      تكشفُه، ولا شيءَ في `tsc`.
    */
    const css = readFileSync(join(SRC, "app", "globals.css"), "utf8");

    expect(css).toMatch(/html\s*\{[^}]*color-scheme:\s*light/);
    expect(css).toMatch(/html\[data-theme="dark"\]\s*\{[^}]*color-scheme:\s*dark/);
  });

  it("knows the tokens that DO exist, so the check above cannot pass by finding nothing", () => {
    const tokens = definedTokens();

    // The guard's own premise: if the parse broke, every name would be "missing"
    // and the test above would still be green only because nothing matched.
    expect(tokens.has("secondary")).toBe(true);
    expect(tokens.has("secondary-ink")).toBe(true);
    expect(tokens.has("success")).toBe(false);
  });
});
