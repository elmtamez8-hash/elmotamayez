/**
 * Countries offered at signup, with their E.164 dial codes.
 *
 * Deliberately short: the platform launches from Qatar into the Arab world, and
 * a 200-entry list would bury the six answers that cover almost every visitor.
 * Add a row when a market opens — no other change is needed.
 */
export interface Country {
  code: string;
  name: string;
  dial: string;
}

export const COUNTRIES: Country[] = [
  { code: "QA", name: "قطر", dial: "+974" },
  { code: "SA", name: "السعودية", dial: "+966" },
  { code: "AE", name: "الإمارات", dial: "+971" },
  { code: "KW", name: "الكويت", dial: "+965" },
  { code: "BH", name: "البحرين", dial: "+973" },
  { code: "OM", name: "عُمان", dial: "+968" },
  { code: "EG", name: "مصر", dial: "+20" },
  { code: "JO", name: "الأردن", dial: "+962" },
  { code: "LB", name: "لبنان", dial: "+961" },
  { code: "MA", name: "المغرب", dial: "+212" },
];

export const DEFAULT_COUNTRY = COUNTRIES[0];
