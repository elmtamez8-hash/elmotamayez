/**
 * Arabic-Indic digits, everywhere, on every surface.
 *
 * ⚠️ THIS EXISTS BECAUSE THE PRODUCT WAS SHOWING BOTH. The home stats read
 * «٤٤٢» while the trust ring on a profile read «91», the star rating read
 * «4.8 (48)», and the trust bars read «97٪» — three numeral systems on one
 * page, in a product whose fifth principle is that Arabic is the original and
 * not a translation. Mixed numerals are the tell of a layout translated rather
 * than authored.
 *
 * `toLocaleString()` is the whole mechanism; these wrappers exist so the locale
 * string is written once and a `.toFixed()` cannot slip a Latin decimal point
 * back in — `(4.8).toFixed(1)` returns "4.8", not "٤٫٨".
 *
 * ⛔ **AND THE NUMBERING SYSTEM IS PINNED, NOT INHERITED — MEASURED ON
 * PRODUCTION RATHER THAN REASONED ABOUT.** `ar-QA` ALONE answers «١٠٣» on ICU
 * 77 and «103» on ICU 78: the same source produces two shapes, because CLDR
 * changed this locale's default numbering system. So every number on every
 * screen hung on whichever ICU the visitor's browser shipped, and the whole
 * product would flip to Latin digits on its own — with nothing in any diff, and
 * the mixed-numeral defect above reappearing by a road nobody walked down.
 * `CountedNoun.php` carries the identical fix and the identical paragraph on
 * the server.
 *
 * ⚠️ **MONEY AND DATES ARE DELIBERATELY NOT THIS.** `formatMoney()` and
 * `formatDate()` in `labels.ts` pin `latn` on purpose — a price and a calendar
 * date read in Western digits across this product — so this is one of two
 * decisions, not the only one.
 *
 * ⚠️ **AND IT IS THE ONE SPELLING**: `no-unpinned-numerals.test.ts` fails the
 * build over a bare `toLocaleString("ar…")` anywhere under `src/`. Thirty-two
 * sites had written it out by hand, in two different locales.
 */

const LOCALE = "ar-QA-u-nu-arab";

/** Whole numbers: counts, scores, percentages. */
export function arabicNumber(value: number): string {
  return value.toLocaleString(LOCALE);
}

/** Fixed decimals — ratings. Keeps the Arabic decimal separator «٫». */
export function arabicDecimal(value: number, digits = 1): string {
  return value.toLocaleString(LOCALE, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
}

/**
 * Every Western digit in a string as its Arabic-Indic twin, and nothing else
 * touched — for numbers that are TEXT rather than quantities: a year, a version,
 * a phone number.
 *
 * ⚠️ NOT `arabicNumber()`, WHICH GROUPS. `arabicNumber(2026)` is «٢٬٠٢٦» — a
 * thousands separator inside a year — and a phone number or «1.1» is not a
 * number to format at all: its separators and leading zeros are part of it.
 */
export function arabicDigits(text: string | number): string {
  return String(text).replace(/[0-9]/g, (digit) => String.fromCharCode(0x0660 + Number(digit)));
}
