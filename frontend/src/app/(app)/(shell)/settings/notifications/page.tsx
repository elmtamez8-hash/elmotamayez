"use client";

import { useCallback, useEffect, useState } from "react";

import { errorMessage, fieldErrors } from "@/lib/api";
import {
  notifications,
  type NotificationChannel,
  type NotificationPreference,
  type NotificationTypeMeta,
} from "@/lib/notifications";

/**
 * A type × channel grid.
 *
 * Only implemented channels appear — WhatsApp is a known value with no code
 * behind it, and a greyed-out toggle would promise a date nobody has committed
 * to. Mandatory types render locked with the reason visible rather than absent,
 * because a user looking for the switch should find out why there isn't one.
 */
export default function NotificationSettingsPage() {
  const [channels, setChannels] = useState<NotificationChannel[]>([]);
  const [types, setTypes] = useState<NotificationTypeMeta[]>([]);
  const [selected, setSelected] = useState<Record<string, string[]>>({});
  const [quietStart, setQuietStart] = useState("");
  const [quietEnd, setQuietEnd] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const load = useCallback(async () => {
    try {
      const [meta, stored] = await Promise.all([
        notifications.types(),
        notifications.preferences(),
      ]);

      setChannels(meta.channels);
      setTypes(meta.types);

      // A type with no stored row falls back to its defaults: absence means
      // "never chose", not "chose nothing".
      const byType: Record<string, string[]> = {};
      for (const type of meta.types) {
        const match = stored.preferences.find(
          (preference: NotificationPreference) => preference.type === type.key,
        );
        byType[type.key] = match ? match.channels : type.default_channels;
      }
      setSelected(byType);
    } catch (err) {
      setError(errorMessage(err, "تعذّر تحميل إعدادات الإشعارات."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = (typeKey: string, channelKey: string) => {
    setSaved(false);
    setSelected((current) => {
      const existing = current[typeKey] ?? [];
      return {
        ...current,
        [typeKey]: existing.includes(channelKey)
          ? existing.filter((value) => value !== channelKey)
          : [...existing, channelKey],
      };
    });
  };

  const save = async () => {
    setSaving(true);
    setError(null);
    setSaved(false);

    try {
      await notifications.updatePreferences(
        types.map((type) => ({
          type: type.key,
          channels: selected[type.key] ?? [],
          digest_window_minutes: null,
        })),
      );

      if (quietStart && quietEnd) {
        await notifications.updateQuietHours({
          quiet_hours_start: quietStart,
          quiet_hours_end: quietEnd,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        });
      }

      setSaved(true);
    } catch (err) {
      // 422 lands under its field; everything else goes through the Arabic table.
      const fields = fieldErrors(err);
      const first = Object.values(fields)[0];
      setError(first ?? errorMessage(err, "تعذّر حفظ الإعدادات."));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <p className="text-ink-muted">جارٍ التحميل…</p>;

  return (
    <div className="mx-auto max-w-3xl">
      <p className="mb-6 text-sm text-ink-muted">
        اختر كيف تصلك كل فئة من الإشعارات. الإشعارات الإلزامية لا يمكن إيقافها.
      </p>

      {error && (
        <p role="alert" className="mb-4 rounded-lg border border-danger bg-surface-raised px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}
      {saved && (
        <p role="status" className="mb-4 rounded-lg border border-secondary bg-surface-raised px-4 py-3 text-sm text-secondary-ink">
          حُفظت الإعدادات.
        </p>
      )}

      <div className="overflow-x-auto rounded-lg border border-line bg-surface-raised">
        <table className="w-full text-start text-sm">
          <thead>
            <tr className="border-b border-line">
              <th scope="col" className="p-3 text-start font-semibold text-ink">نوع الإشعار</th>
              {channels.map((channel) => (
                <th key={channel.key} scope="col" className="p-3 text-start font-semibold text-ink">
                  {channel.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {types.map((type) => (
              <tr key={type.key} className="border-b border-line last:border-b-0">
                <th scope="row" className="p-3 text-start font-normal text-ink">
                  {type.label}
                  {type.is_mandatory && (
                    <span className="ms-2 rounded border border-line px-1.5 py-0.5 text-xs text-ink-muted">
                      إلزامي
                    </span>
                  )}
                </th>
                {channels.map((channel) => {
                  const checked = (selected[type.key] ?? []).includes(channel.key);
                  const locked = type.is_mandatory && type.default_channels.includes(channel.key);

                  return (
                    <td key={channel.key} className="p-3">
                      <input
                        type="checkbox"
                        checked={checked || locked}
                        disabled={locked}
                        onChange={() => toggle(type.key, channel.key)}
                        aria-label={`${type.label} على ${channel.label}`}
                        title={locked ? "إشعار إلزامي لا يمكن إيقافه" : undefined}
                        className="h-4 w-4 accent-primary disabled:opacity-60"
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <fieldset className="mt-8 rounded-lg border border-line bg-surface-raised p-4">
        <legend className="px-2 text-sm font-semibold text-ink">فترة الهدوء</legend>
        <p className="mb-4 text-sm text-ink-muted">
          لن تصلك الإشعارات غير الإلزامية على القنوات الخارجية خلال هذه الفترة، وتُرسَل بعدها.
        </p>
        <div className="flex flex-wrap gap-4">
          <label className="text-sm text-ink">
            من
            <input
              type="time"
              value={quietStart}
              onChange={(event) => setQuietStart(event.target.value)}
              className="ms-2 rounded-lg border border-line bg-surface px-2 py-1 text-ink"
            />
          </label>
          <label className="text-sm text-ink">
            إلى
            <input
              type="time"
              value={quietEnd}
              onChange={(event) => setQuietEnd(event.target.value)}
              className="ms-2 rounded-lg border border-line bg-surface px-2 py-1 text-ink"
            />
          </label>
        </div>
      </fieldset>

      <button
        type="button"
        onClick={save}
        disabled={saving}
        className="mt-6 rounded-lg bg-primary px-4 py-2 font-medium text-white transition hover:opacity-90 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        {saving ? "جارٍ الحفظ…" : "حفظ"}
      </button>
    </div>
  );
}
