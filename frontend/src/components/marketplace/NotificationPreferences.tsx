"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { NotificationPreferences as Preferences } from "@/lib/types";

const TOGGLES = [
  {
    key: "weekly_reports" as const,
    label: "تقرير أسبوعي",
    hint: "ملخّص عن حصص أبنائك وتقدّمهم، مرة كل أسبوع.",
  },
  {
    key: "session_alerts" as const,
    label: "تنبيهات الحصص",
    hint: "تذكير قبل موعد كل حصة، وإشعار عند أي تغيير فيها.",
  },
];

/**
 * Notification preferences (FR-077).
 *
 * Each toggle saves on change rather than behind a "save" button: there are two
 * switches, and a form that can be left unsaved is a worse trade than one extra
 * request. A failed save flips the switch back so the UI never claims a setting
 * that did not persist.
 */
export function NotificationPreferences() {
  const [prefs, setPrefs] = useState<Preferences | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api
      .get<Preferences>("/parent/notification-preferences")
      .then(setPrefs)
      .catch(() => setError("تعذّر تحميل تفضيلات الإشعارات."));
  }, []);

  async function toggle(key: keyof Preferences, value: boolean) {
    if (!prefs) return;

    const next = { ...prefs, [key]: value };
    setPrefs(next);
    setError("");

    try {
      setPrefs(await api.put<Preferences>("/parent/notification-preferences", next));
    } catch (err: unknown) {
      setPrefs(prefs);
      setError(errorMessage(err, "تعذّر حفظ التفضيل."));
    }
  }

  if (!prefs) return null;

  return (
    <section aria-labelledby="prefs-heading" className="rounded-2xl border border-line p-6">
      <h2 id="prefs-heading" className="mb-4 text-lg font-bold text-ink">
        تفضيلات الإشعارات
      </h2>

      {error && (
        <p role="alert" className="mb-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      <ul className="space-y-4">
        {TOGGLES.map((item) => (
          <li key={item.key}>
            <label className="flex items-start justify-between gap-4">
              <span>
                <span className="block text-sm font-medium text-ink">{item.label}</span>
                <span className="block text-sm text-ink-muted">{item.hint}</span>
              </span>
              <input
                type="checkbox"
                role="switch"
                checked={prefs[item.key]}
                onChange={(event) => toggle(item.key, event.target.checked)}
                className="mt-1 h-5 w-9 shrink-0 accent-primary"
              />
            </label>
          </li>
        ))}
      </ul>
    </section>
  );
}
