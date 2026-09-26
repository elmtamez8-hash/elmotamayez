/**
 * Arabic place names for the IANA zones a reader of this product is likely to
 * pick — «قطر — الدوحة», not `Asia/Qatar`.
 *
 * ⚠️ THE PICKER LISTED 418 RAW ENGLISH NAMES. `Intl.supportedValuesOf("timeZone")`
 * is the whole catalogue, and a parent in Doha scrolled past `America/Argentina/…`
 * to find their own city spelled in a language the product does not otherwise
 * use. This map is deliberately NOT the whole catalogue: the Arab world, then the
 * zones the diaspora lives in. A zone without an entry keeps its IANA name — still
 * correct, still unique, and a blank would hide it.
 *
 * Country first, then the city the zone is named after: the city is what makes
 * two zones of one country (`America/New_York` · `America/Los_Angeles`) read
 * differently, and the country is what a reader scans for.
 */
const ZONE_PLACES: Record<string, string> = {
  // The two countries the product serves.
  "Asia/Qatar": "قطر — الدوحة",
  "Africa/Cairo": "مصر — القاهرة",

  // The Gulf.
  "Asia/Riyadh": "السعودية — الرياض",
  "Asia/Dubai": "الإمارات — دبي",
  "Asia/Kuwait": "الكويت — الكويت",
  "Asia/Bahrain": "البحرين — المنامة",
  "Asia/Muscat": "عُمان — مسقط",

  // The Levant, Iraq and Yemen.
  "Asia/Baghdad": "العراق — بغداد",
  "Asia/Amman": "الأردن — عمّان",
  "Asia/Damascus": "سوريا — دمشق",
  "Asia/Beirut": "لبنان — بيروت",
  "Asia/Gaza": "فلسطين — غزة",
  "Asia/Hebron": "فلسطين — الخليل",
  "Asia/Aden": "اليمن — عدن",

  // North and East Africa.
  "Africa/Khartoum": "السودان — الخرطوم",
  "Africa/Tripoli": "ليبيا — طرابلس",
  "Africa/Tunis": "تونس — تونس",
  "Africa/Algiers": "الجزائر — الجزائر",
  "Africa/Casablanca": "المغرب — الدار البيضاء",
  "Africa/Nouakchott": "موريتانيا — نواكشوط",
  "Africa/Mogadishu": "الصومال — مقديشو",
  "Africa/Djibouti": "جيبوتي — جيبوتي",
  "Indian/Comoro": "جزر القمر — موروني",

  // Where families abroad live.
  "Europe/Istanbul": "تركيا — إسطنبول",
  "Europe/London": "المملكة المتحدة — لندن",
  "Europe/Dublin": "أيرلندا — دبلن",
  "Europe/Paris": "فرنسا — باريس",
  "Europe/Berlin": "ألمانيا — برلين",
  "Europe/Amsterdam": "هولندا — أمستردام",
  "Europe/Brussels": "بلجيكا — بروكسل",
  "Europe/Madrid": "إسبانيا — مدريد",
  "Europe/Rome": "إيطاليا — روما",
  "Europe/Vienna": "النمسا — فيينا",
  "Europe/Stockholm": "السويد — ستوكهولم",
  "Europe/Oslo": "النرويج — أوسلو",
  "Europe/Copenhagen": "الدنمارك — كوبنهاغن",
  "Europe/Athens": "اليونان — أثينا",
  "Europe/Moscow": "روسيا — موسكو",
  "Asia/Tehran": "إيران — طهران",
  "Asia/Karachi": "باكستان — كراتشي",
  "Asia/Kolkata": "الهند — كولكاتا",
  "Asia/Dhaka": "بنغلاديش — دكا",
  "Asia/Kuala_Lumpur": "ماليزيا — كوالالمبور",
  "Asia/Jakarta": "إندونيسيا — جاكرتا",
  "Asia/Singapore": "سنغافورة — سنغافورة",
  "Asia/Shanghai": "الصين — شنغهاي",
  "Asia/Tokyo": "اليابان — طوكيو",
  "Australia/Sydney": "أستراليا — سيدني",
  "Africa/Lagos": "نيجيريا — لاغوس",
  "Africa/Nairobi": "كينيا — نيروبي",
  "Africa/Johannesburg": "جنوب أفريقيا — جوهانسبرغ",
  "America/New_York": "الولايات المتحدة — نيويورك",
  "America/Chicago": "الولايات المتحدة — شيكاغو",
  "America/Denver": "الولايات المتحدة — دنفر",
  "America/Los_Angeles": "الولايات المتحدة — لوس أنجلوس",
  "America/Toronto": "كندا — تورونتو",
  "America/Sao_Paulo": "البرازيل — ساو باولو",
  UTC: "التوقيت العالمي (UTC)",
};

/** The Arabic place for a zone, or `null` when nobody has named it yet. */
export function timezonePlace(zone: string): string | null {
  return ZONE_PLACES[zone] ?? null;
}
