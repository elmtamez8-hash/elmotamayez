import ZONE_ALIASES_JSON from "./timezone-aliases.json";
import ZONE_PLACES_JSON from "./timezone-names.json";

/**
 * Arabic place names for EVERY IANA zone — «قطر — الدوحة», not `Asia/Qatar`.
 *
 * ⚠️ THE PICKER LISTED 418 RAW ENGLISH NAMES. `Intl.supportedValuesOf("timeZone")`
 * is the whole catalogue, and a parent in Doha scrolled past `America/Argentina/…`
 * to find their own city spelled in a language the product does not otherwise
 * use. The first map named only the Arab world and the diaspora and left the
 * rest in English; the owner asked for the rest too (2026-09-27), so the map now
 * covers the whole catalogue: every zone this runtime lists (Node/Chrome, which
 * still say `Asia/Calcutta`, `Europe/Kiev` …) AND every zone PHP's
 * `timezone_identifiers_list()` accepts (`Asia/Kolkata`, `Europe/Kyiv` …). An old
 * and a new spelling of one zone share one label; no list holds both.
 * `timezone-names.test.ts` fails the day a runtime zone has no name or two zones
 * in one list read the same.
 *
 * Country first, then the city the zone is named after: the city is what makes
 * two zones of one country (`America/New_York` · `America/Los_Angeles`) read
 * differently, and the country is what a reader scans for. A zone named after a
 * country (`Asia/Qatar`, `America/Jamaica`) takes its capital; a zone in
 * Antarctica takes «القارة القطبية الجنوبية» and the station.
 *
 * `UTC` is not a country, so not «البلد — المدينة»: «غرينتش (UTC)» (owner
 * decision 2026-09-27), because every sentence writes «توقيت» before the label
 * and «توقيت التوقيت العالمي» said it twice.
 *
 * ⛔ THE DATA IS JSON, AND THE SERVER HOLDS AN IDENTICAL COPY at
 * `backend/app/Shared/Support/timezone-names.json` — the two apps build from
 * separate Docker contexts, so neither can read the other's file. Edit one,
 * copy it over the other; `TimezoneLabelParityTest` fails the build the day the
 * two disagree.
 */
const ZONE_PLACES: Readonly<Record<string, string>> = ZONE_PLACES_JSON;

/**
 * The Arabic place for a zone, or `null` for a name that is not in the map —
 * which, since the map covers the catalogue, means a zone some future runtime
 * added. The callers keep the IANA name then: still correct, and a blank would
 * hide it.
 */
export function timezonePlace(zone: string): string | null {
  return ZONE_PLACES[zone] ?? null;
}

const ZONE_ALIASES: Readonly<Record<string, string>> = ZONE_ALIASES_JSON;

/**
 * The one spelling of a zone the server stores — `Asia/Kolkata` for the
 * `Asia/Calcutta` Chrome and Node still report (19 such names, measured
 * 2026-09-27).
 *
 * ⛔ THE SERVER REFUSED THE OLD SPELLING, so a reader in India who picked
 * «الهند — كولكاتا» got «المنطقة الزمنية غير معروفة». Owner decision
 * 2026-09-27: the server accepts it and stores the new one, and the client folds
 * it first — the picker lists each zone ONCE, and the sign-in stamp compares the
 * browser's zone with the stored one in the same spelling (`Asia/Calcutta` !==
 * `Asia/Kolkata` would re-send the stamp on every page load).
 *
 * The map is `timezone-aliases.json`, an identical copy of the server's
 * (`backend/app/Shared/Support/timezone-aliases.json`, derived from ICU's
 * `getIanaID()`); `TimezoneLabelParityTest` holds the two together.
 */
export function canonicalZone(zone: string): string {
  return ZONE_ALIASES[zone] ?? zone;
}
