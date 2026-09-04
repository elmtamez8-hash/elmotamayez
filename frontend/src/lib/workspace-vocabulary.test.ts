import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
 * SC-002 — «صفر ظهورٍ لمصطلح مساحة العمل في أيّ نصٍّ يقرؤه مستخدم».
 *
 * ⚠️ حارسٌ مشتقّ، لا مشية. «صفر ظهور» وعدٌ يصدأ عند أوّل شاشةٍ تُكتَب بعدنا، والسابقةُ
 * في هذا المستودع `theme-tokens.test.ts`: يقرأ الحقيقة من المصدر ولا يحمل قائمةً
 * ثانية.
 *
 * ⚠️ والإبرةُ الجذرُ «مساح»، لا المصطلحُ «مساحة عمل». التاءُ المربوطةُ تصير تاءً قبل
 * الضمير، فـ«مساحتك» **لا تحوي** «مساحة» — والفرقُ مقيس: المصطلحُ الضيّق كان يُعطي
 * ١٣ موضعًا في ٦ ملفّات، والجذرُ يُعطي ٢٦ في ١٥. فالإبرةُ الضيّقة تمرّ خضراءَ فوق
 * ملفّاتٍ جدولتها الخطّةُ نفسُها، وتُفوّت خمسةً لم تذكرها إطلاقًا.
 *
 * ⚠️ ويجرّد التعليقات قبل المسح. سببُ كلّ إصلاحٍ في هذه الشجرة مكتوبٌ بجوار ما
 * أُصلِح — بما فيه هذا الملفّ — فحارسٌ يقرأ التعليقات يسقط على الشرح نفسِه ويعلّم
 * الناسَ حذفَ الشرح. `theme-tokens.test.ts` و`ContextIsolationTest` كلاهما يفعلها.
 */

const ROOT = join(process.cwd(), "src");

/** الجذر: يلتقط «مساحة» و«مساحات» و«مساحتك» و«المساحة» معًا. */
const NEEDLE = "مساح";

/**
 * «المساحة» بمعنى الحيّز، لا بمعنى المستأجر.
 *
 * ⚠️ استثناءاتٌ مسمّاةٌ بالسطر، لا إبرةٌ ضيّقةٌ تُرضي نفسها. قائمةٌ فارغةٌ اليوم
 * ومكانُها محجوز: أوّلُ «مساحة تخزين» أو «مساحة فارغة» تُكتَب تُضاف هنا بسطرها،
 * فيبقى الحارسُ يقرأ الجذرَ ويبقى الاستثناءُ مقروءًا بجوار سببه.
 */
const ALLOWED_LINES: RegExp[] = [
  // مثال: /مساحة التخزين/  — حيّزٌ على قرص، لا مكانُ عمل.
];

/** كلّ ملفّات المصدر عدا الاختبارات. */
function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) return sourceFiles(path);
    if (!/\.tsx?$/.test(entry)) return [];
    if (/\.test\.tsx?$/.test(entry)) return [];

    return [path];
  });
}

/**
 * إزالة التعليقات قبل المسح.
 *
 * ⚠️ السلاسل النصّية تبقى — هي المقصودة. والدقّةُ المطلوبةُ هنا «لا تسقط على شرح»،
 * لا مُحلِّلٌ كامل: `//` داخل سلسلةٍ نصّية (عنوان URL مثلًا) لا يحمل الجذر العربيّ،
 * فأسوأُ ما يفعله هذا التبسيطُ أن يتجاهل نصًّا لا يعنينا.
 */
function stripComments(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|[^:])\/\/.*$/gm, "$1");
}

describe("SC-002 — مفردة «مساحة العمل» لا يقرؤها مستخدم", () => {
  it("لا يذكر الجذر «مساح» في أيّ ملفّ مصدر خارج التعليقات", () => {
    const offenders: string[] = [];

    for (const file of sourceFiles(ROOT)) {
      const lines = stripComments(readFileSync(file, "utf8")).split("\n");

      lines.forEach((line, index) => {
        if (!line.includes(NEEDLE)) return;
        if (ALLOWED_LINES.some((allowed) => allowed.test(line))) return;

        // اسمُ الملفِّ ورقمُ السطر: ما يحتاجه القارئُ ليصلح، لا عدد.
        offenders.push(
          `${file.replace(ROOT, "src")}:${index + 1} — ${line.trim().slice(0, 100)}`,
        );
      });
    }

    expect(offenders).toEqual([]);
  });
});
