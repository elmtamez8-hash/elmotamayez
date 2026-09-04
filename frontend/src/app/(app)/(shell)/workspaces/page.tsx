"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { roleLabel } from "@/lib/labels";
import type { Workspace } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

/*
 * أماكن العمل — وارثةُ صفحةِ «مساحات العمل» في مكانها (مواصفة ٠٢٥).
 *
 * ⚠️ أُعيدَ نصُّها هنا ولم يُنشأْ مسارٌ ثانٍ. ملفّانِ يُجيبانِ مسارًا واحدًا
 * يُعطِّلانِ التطبيقَ كلَّه بـ500 — لا الصفحتَينِ وحدَهما — وهو ثمنٌ دفعه هذا
 * المستودعُ مرّةً وسجّله.
 *
 * وثلاثُ حالاتٍ لا واحدة (FR-014 · FR-014أ · FR-025):
 *   صفر  → جملةٌ واحدةٌ وطريقٌ إلى الدراسة. لا قائمةَ فارغةً تحت عنوانٍ لا يعنيه،
 *          ولا زرَّ إنشاءٍ يعرضُ على طالبةٍ فضوليّةٍ ما لا حقَّ لها فيه.
 *   واحد → اسمُ المكانِ نفسِه عنوانًا، لا صيغةَ مفردٍ من جمع: «مكان عملي» تُبقي في
 *          ذهن القارئ أنّ ثمّةَ أماكنَ أخرى، وهو ما تُلغيه هذه المواصفة.
 *   أكثر → «أماكن عملي»، وهي حالةُ من يملكُ مكانَه ويساعدُ عند غيره.
 */

export default function WorkplacesPage() {
  const [places, setPlaces] = useState<Workspace[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [switching, setSwitching] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Workspace[] }>("/workspaces")
      .then((res) => setPlaces(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const switchTo = async (uuid: string) => {
    setSwitching(uuid);
    setError("");
    try {
      await api.post(`/workspaces/${uuid}/switch`);
      // إعادةُ تحميلٍ كاملة، لا تحديثَ موجِّه: المكانُ حالةٌ على الخادم، وكلُّ
      // قائمةٍ مخزَّنةٍ على العميل تخصُّ المكانَ السابق.
      window.location.reload();
    } catch (err: unknown) {
      setError(userMessage(err));
      setSwitching(null);
    }
  };

  const single = places.length === 1 ? places[0] : null;
  const heading = single ? single.name : "أماكن عملي";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold text-ink">
          {loading || failed ? "أماكن عملي" : heading}
        </h2>
      </div>

      {error && <Alert tone="danger" title={error} />}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : places.length === 0 ? (
        <EmptyState
          title="لا مكان عمل لهذا الحساب"
          description="أماكن العمل للمدرّسين ومساعديهم. حسابك للدراسة، وكورساتك في صفحة «دراستي»."
          action={<Button href="/enrollments">إلى دراستي</Button>}
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {places.map((ws) => (
            <Card key={ws.uuid} as="article" padding="sm">
              <div className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h3 className="truncate font-semibold text-ink">{ws.name}</h3>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {/* لافتةُ «الحالي» لا معنى لها حين لا ثانيَ له. */}
                  {ws.is_current && places.length > 1 && (
                    <Badge tone="success">الحالي</Badge>
                  )}
                  {ws.pivot_role && (
                    <Badge tone="info">{roleLabel(ws.pivot_role, ws.pivot_role_label)}</Badge>
                  )}
                </div>
              </div>

              <div className="flex gap-2">
                {places.length > 1 && (
                  <Button
                    variant="secondary"
                    fullWidth
                    disabled={ws.is_current}
                    loading={switching === ws.uuid}
                    loadingLabel="جارٍ التبديل…"
                    onClick={() => switchTo(ws.uuid)}
                  >
                    {ws.is_current ? "أنت هنا" : "انتقل إليه"}
                  </Button>
                )}
                {(ws.is_owner || ws.pivot_role === "tenant-owner") && (
                  <Button
                    href={`/workspaces/${ws.uuid}/edit`}
                    variant={places.length > 1 ? "ghost" : "secondary"}
                    fullWidth={places.length === 1}
                  >
                    الإعدادات
                  </Button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
