/**
 * Countries offered at signup, with their E.164 dial codes.
 *
 * Deliberately short: the platform launches from Qatar into the Arab world, and
 * a 200-entry list would bury the six answers that cover almost every visitor.
 * Add a row when a market opens — no other change is needed.
 */
export interface Country {
  code: string;
  name_ar: string;
  dial: string;
}

export const COUNTRIES: Country[] = [
  { code: "QA", name_ar: "قطر", dial: "+974" },
  { code: "SA", name_ar: "السعودية", dial: "+966" },
  { code: "AE", name_ar: "الإمارات", dial: "+971" },
  { code: "KW", name_ar: "الكويت", dial: "+965" },
  { code: "BH", name_ar: "البحرين", dial: "+973" },
  { code: "OM", name_ar: "عُمان", dial: "+968" },
  { code: "EG", name_ar: "مصر", dial: "+20" },
  { code: "JO", name_ar: "الأردن", dial: "+962" },
  { code: "LB", name_ar: "لبنان", dial: "+961" },
  { code: "MA", name_ar: "المغرب", dial: "+212" },
];

export const DEFAULT_COUNTRY = COUNTRIES[0];
