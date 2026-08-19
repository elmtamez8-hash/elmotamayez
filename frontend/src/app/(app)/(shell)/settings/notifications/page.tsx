"use client";

import { useCallback, useEffect, useState } from "react";

import { WhatsAppVerification } from "@/components/settings/WhatsAppVerification";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
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
 * Only implemented channels appear — Telegram, SMS and push are known values
 * with no code behind them, and a greyed-out toggle would promise a date nobody
 * has committed to. Mandatory types render locked with the reason visible rather
 * than absent, because a user looking for the switch should find out why there
 * isn't one.
 *
 * Spec 020 added WhatsApp, and with it the verification card below: a tick box
 * on this grid does nothing at all until the number under it has been proven,
 * because the channel refuses to reach an unverified one. The two belong on one
 * screen for exactly that reason.
 */
export default function NotificationSettingsPage() {
  const [channels, setChannels] = useState<NotificationChannel[]>([]);
  const [types, setTypes] = useState<NotificationTypeMeta[]>([]);
  const [selected, setSelected] = useState<Record<string, string[]>>({});
  const [quietStart, setQuietStart] = useState("");
  const [quietEnd, setQuietEnd] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
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
    setError("");
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
      setError(Object.values(fields)[0] ?? errorMessage(err, "تعذّر حفظ الإعدادات."));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <p className="text-ink-muted">جارٍ التحميل…</p>;

  return (
    <div className="mx-auto max-w-2xl space-y-8">
      <h2 className="text-2xl font-bold text-ink">إعدادات الإشعارات</h2>

      {error && <Alert tone="danger" title={error} />}
      {saved && <Alert tone="success" title="حُفِظت الإعدادات." />}

      <Card as="section">
        <h3 className="mb-1 font-semibold text-ink">كيف تصلك الإشعارات</h3>
        <p className="mb-4 text-sm text-ink-muted">
          اختر القنوات لكل فئة. الإشعارات الإلزامية لا يمكن إيقافها.
        </p>

        <div className="overflow-x-auto">
          <table className="w-full text-start text-sm">
            <thead>
              <tr className="border-b border-line">
                <th scope="col" className="pb-3 text-start font-semibold text-ink">
                  الفئة
                </th>
                {channels.map((channel) => (
                  <th
                    key={channel.key}
                    scope="col"
                    className="pb-3 text-start font-semibold text-ink"
                  >
                    {channel.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {types.map((type) => (
                <tr key={type.key} className="border-b border-line last:border-b-0">
                  <th scope="row" className="py-3 pe-4 text-start font-normal text-ink">
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
                      <td key={channel.key} className="py-3">
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
      </Card>

      {channels.some((channel) => channel.key === "whatsapp") && (
        <Card as="section">
          <h3 className="mb-1 font-semibold text-ink">رقم واتساب</h3>
          <p className="mb-4 text-sm text-ink-muted">
            أكّد رقمك ليصلك ما اخترته أعلاه على واتساب. لا تُرسَل أي رسالة إلى رقم غير
            مؤكَّد.
          </p>

          <WhatsAppVerification />
        </Card>
      )}

      <Card as="section">
        <h3 className="mb-1 font-semibold text-ink">فترة الهدوء</h3>
        <p className="mb-4 text-sm text-ink-muted">
          لن تصلك الإشعارات غير الإلزامية على القنوات الخارجية خلال هذه الفترة، وتُرسَل
          بعد انتهائها بدل أن تُلغى.
        </p>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <TextField
            id="quiet_hours_start"
            label="من"
            type="time"
            value={quietStart}
            onChange={setQuietStart}
          />
          <TextField
            id="quiet_hours_end"
            label="إلى"
            type="time"
            value={quietEnd}
            onChange={setQuietEnd}
            hint="اتركهما فارغين لإلغاء فترة الهدوء."
          />
        </div>
      </Card>

      <Button onClick={save} loading={saving} loadingLabel="جارٍ الحفظ…">
        احفظ التغييرات
      </Button>
    </div>
  );
}
