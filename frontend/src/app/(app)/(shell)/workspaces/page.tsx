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

const WORKSPACE_TYPES: Record<string, string> = {
  academy: "أكاديمية",
  individual: "مدرّس مستقل",
  school: "مدرسة",
};

export default function WorkspacesPage() {
  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [switching, setSwitching] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Workspace[] }>("/workspaces")
      .then((res) => setWorkspaces(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const switchTo = async (uuid: string) => {
    setSwitching(uuid);
    setError("");
    try {
      await api.post(`/workspaces/${uuid}/switch`);
      // A full reload, not a router refresh: the workspace is server-side
      // session state and every cached list on the client belongs to the old one.
      window.location.reload();
    } catch (err: unknown) {
      setError(userMessage(err));
      setSwitching(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold text-ink">مساحات العمل</h2>
        <Button href="/workspaces/new">مساحة عمل جديدة</Button>
      </div>

      {error && <Alert tone="danger" title={error} />}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : workspaces.length === 0 ? (
        <EmptyState
          title="لا مساحات عمل"
          description="أنشئ مساحة عمل لتبدأ بإضافة كورساتك وطلابك."
          action={<Button href="/workspaces/new">مساحة عمل جديدة</Button>}
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {workspaces.map((ws) => (
            <Card key={ws.uuid} as="article" padding="sm">
              <div className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h3 className="truncate font-semibold text-ink">{ws.name}</h3>
                  <p className="text-sm text-ink-muted">
                    {WORKSPACE_TYPES[ws.type] ?? ws.type}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {ws.is_current && <Badge tone="success">الحالية</Badge>}
                  {ws.pivot_role && (
                    <Badge tone="info">{roleLabel(ws.pivot_role, ws.pivot_role_label)}</Badge>
                  )}
                </div>
              </div>

              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  fullWidth
                  disabled={ws.is_current}
                  loading={switching === ws.uuid}
                  loadingLabel="جارٍ التبديل…"
                  onClick={() => switchTo(ws.uuid)}
                >
                  {ws.is_current ? "أنت هنا" : "انتقل إليها"}
                </Button>
                {(ws.is_owner || ws.pivot_role === "tenant-owner") && (
                  <Button href={`/workspaces/${ws.uuid}/edit`} variant="ghost">
                    تعديل
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
