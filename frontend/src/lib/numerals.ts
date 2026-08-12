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
 * `toLocaleString("ar-QA")` is the whole mechanism; these wrappers exist so the
 * locale string is written once and a `.toFixed()` cannot slip a Latin decimal
 * point back in — `(4.8).toFixed(1)` returns "4.8", not "٤٫٨".
 */

const LOCALE = "ar-QA";

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
