import { describe, expect, it } from "vitest";

import ZONE_ALIASES from "./timezone-aliases.json";
import ZONE_PLACES from "./timezone-names.json";
import { canonicalZone, timezonePlace } from "./timezone-names";

/*
| «ترجم أسماء باقي المحافظات والدول» (owner, 2026-09-27): every zone the picker
| can show is named in Arabic, and no two of them read the same.
|
| ⚠️ The list is the one THIS runtime returns — Node 24 here and on the CI, the
| same ICU data Chrome ships. A runtime that adds a zone fails the first test,
| which is the point: the new zone would otherwise appear in English.
*/

const runtimeZones = Intl.supportedValuesOf("timeZone");

describe("timezone names", () => {
  it("names every zone the runtime lists", () => {
    expect(runtimeZones.length).toBeGreaterThan(400);
    expect(runtimeZones.filter((zone) => timezonePlace(zone) === null)).toEqual([]);
  });

  it("never gives two zones of the picker the same name", () => {
    const labels = runtimeZones.map((zone) => timezonePlace(zone));

    expect(labels.filter((label, i) => labels.indexOf(label) !== i)).toEqual([]);
  });

  it("names only real zones, so a typo in a key cannot hide an unnamed one", () => {
    const unknown = Object.keys(ZONE_PLACES).filter((zone) => {
      try {
        new Intl.DateTimeFormat("en", { timeZone: zone });
        return false;
      } catch {
        return true;
      }
    });

    expect(unknown).toEqual([]);
  });

  it("writes every place as «البلد — المدينة», in Arabic, except Greenwich", () => {
    for (const [zone, label] of Object.entries(ZONE_PLACES)) {
      if (zone === "UTC") {
        expect(label).toBe("غرينتش (UTC)");
        continue;
      }

      expect(label, zone).toMatch(/^[^A-Za-z]+ — [^A-Za-z]+$/);
    }
  });

  it("folds every old spelling the runtime reports into the one the server stores, named alike", () => {
    expect(canonicalZone("Asia/Calcutta")).toBe("Asia/Kolkata");
    expect(canonicalZone("Europe/Kiev")).toBe("Europe/Kyiv");
    expect(canonicalZone("Asia/Qatar")).toBe("Asia/Qatar");

    for (const [alias, zone] of Object.entries(ZONE_ALIASES)) {
      expect(() => new Intl.DateTimeFormat("en", { timeZone: zone }), zone).not.toThrow();
      expect(timezonePlace(zone), zone).toBe(timezonePlace(alias));
      // One step, never a chain.
      expect(canonicalZone(zone), zone).toBe(zone);
    }
  });

  it("leaves no two zones with one name once the runtime's list is folded", () => {
    const zones = [...new Set(runtimeZones.map(canonicalZone))];
    const labels = zones.map((zone) => timezonePlace(zone));

    expect(zones.filter((zone) => zone in ZONE_ALIASES)).toEqual([]);
    expect(labels.filter((label, i) => labels.indexOf(label) !== i)).toEqual([]);
  });
});
