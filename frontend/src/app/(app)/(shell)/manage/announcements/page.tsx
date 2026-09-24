"use client";

import { useCallback, useEffect, useState } from "react";

import { AnnouncementForm } from "@/components/community/AnnouncementForm";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { BellIcon, EyeIcon, SparkIcon, UsersIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import {
  announcements,
  type Announcement,
  type AnnouncementInput,
} from "@/lib/announcements";
import { classSessions } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";

/**
 * The teacher's announcements (spec 010 · US6).
 *
 * ⚠️ THE TWO COUNTERS ARE SERVED WITH THE LIST, NEVER FETCHED PER ROW. They are
 * counted live off `notifications(source_type, source_id, read_at)` — `FR-046`
 * forbids storing them, because a stored pair reports more readers than
 * recipients the first time a notification is deleted — and the server stamps
 * both onto the page in one grouped query.
 *
 * ⚠️ AND «من أُبلغوا» CAN GO DOWN, WHICH IS THE HONEST ANSWER RATHER THAN A BUG.
 * The notification row is the evidence; delete it and the count is smaller.
 *
 * ⚠️ THERE IS NO REPLY THREAD HERE AND NONE ANYWHERE (`FR-045`). An answer goes
 * to the private conversation, which is one tap away and already moderated.
 */
export default function AnnouncementsPage() {
  const [rows, setRows] = useState<Announcement[]>([]);
  const [courses, setCourses] = useState<Array<{ uuid: string; title: string }>>([]);
  const [sessions, setSessions] = useState<Array<{ uuid: string; title: string }>>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");

    announcements
      .list()
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  useEffect(() => {
    // The two pickers. A failure here leaves the scope selector with an empty
    // list rather than breaking the page — a workspace-wide notice needs neither.
    api
      // The index pages at 15 by default — a picker needs the whole list, so
      // ask for the controller's ceiling (200) or course 16 cannot be chosen.
      .get<{ data: Array<{ uuid: string; title: string }> }>("/courses?per_page=200")
      .then((response) => setCourses(response.data ?? []))
      .catch(() => setCourses([]));

    classSessions
      .list({ status: "scheduled" })
      .then((response) =>
        setSessions((response.data ?? []).map((s) => ({ uuid: s.uuid, title: s.title }))),
      )
      .catch(() => setSessions([]));
  }, []);

  async function create(input: AnnouncementInput) {
    setBusy(true);
    setError(null);

    try {
      const created = await announcements.create(input);
      /*
       * ⚠️ TWO CALLS, AND THE LIST CARRIES A PUBLISH BUTTON BECAUSE OF IT. Writing
       * and publishing are two decisions and the second is the one that reaches
       * the class — but a publish that fails after a successful create leaves a
       * draft in the list, and without that button there would be no way to ever
       * publish it. A surface is not finished until something reaches it.
       */
      await announcements.publish(created.uuid);
      load();
    } catch (err) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  }

  async function act(work: () => Promise<unknown>) {
    setBusy(true);
    setError(null);

    try {
      await work();
      setEditing(null);
      load();
    } catch (err) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  }


  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={BellIcon}
        title="الإعلانات"
        description="إعلان واحد يصل من اخترتهم وحدهم. يقرأه الطالب في مركز الإشعارات، ويردّ عليك في محادثته الخاصة إن أراد."
      />

      <Card>
        <div className="mb-4">
          <SectionHeading id="new-announcement" Icon={SparkIcon} title="إعلان جديد" />
        </div>
        <AnnouncementForm
          onSubmit={create}
          busy={busy}
          error={error}
          courses={courses}
          sessions={sessions}
        />
      </Card>

      {state === "loading" ? (
        <RowsSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          title="لا توجد إعلانات بعد"
          description="أوّل إعلان تنشره يظهر هنا مع عدد من وصلهم وعدد من قرأوه."
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((announcement) => (
            <li key={announcement.uuid}>
              <Card as="article" interactive>
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <p className="whitespace-pre-line text-ink">{announcement.body}</p>

                  {announcement.is_hidden ? (
                    <Badge tone="neutral">مسحوب</Badge>
                  ) : announcement.is_urgent ? (
                    <Badge tone="danger">عاجل</Badge>
                  ) : null}
                </div>

                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                  <div className="flex items-center gap-1">
                    <dt className="flex items-center gap-1 text-ink-muted">
                      <UsersIcon className="h-4 w-4" />
                      وصلهم
                    </dt>
                    <dd className="font-semibold text-ink">
                      {arabicNumber(announcement.notified_count ?? 0)}
                    </dd>
                  </div>
                  <div className="flex items-center gap-1">
                    <dt className="flex items-center gap-1 text-ink-muted">
                      <EyeIcon className="h-4 w-4" />
                      قرأوه
                    </dt>
                    <dd className="font-semibold text-ink">
                      {arabicNumber(announcement.read_count ?? 0)}
                    </dd>
                  </div>
                </dl>

                {editing === announcement.uuid ? (
                  <div className="mt-4">
                    {/* The scope is locked: the audience has already been told,
                        and moving it would leave one group holding a message
                        meant for another. */}
                    <AnnouncementForm
                      onSubmit={(input) =>
                        act(() => announcements.update(announcement.uuid, input))
                      }
                      busy={busy}
                      // `is_urgent` travels with the edit: left out, the form
                      // started unticked and every correction to an urgent
                      // notice quietly demoted it to a routine one.
                      initial={{
                        body: announcement.body,
                        scope: announcement.scope,
                        is_urgent: announcement.is_urgent,
                      }}
                      scopeLocked
                    />
                    <div className="mt-2">
                      <Button variant="ghost" onClick={() => setEditing(null)} disabled={busy}>
                        إلغاء التعديل
                      </Button>
                    </div>
                  </div>
                ) : (
                  !announcement.is_hidden && (
                    <div className="mt-4 flex flex-wrap gap-2">
                      {!announcement.is_published && (
                        // A draft left behind by a publish that failed. Without
                        // this there is no way to ever send it.
                        <Button
                          variant="primary"
                          onClick={() => act(() => announcements.publish(announcement.uuid))}
                          disabled={busy}
                        >
                          نشر
                        </Button>
                      )}

                      {/* FR-047 — a correction reaches everyone already holding
                          it, because the notification IS the delivery. */}
                      <Button
                        variant="ghost"
                        onClick={() => setEditing(announcement.uuid)}
                        disabled={busy}
                      >
                        تعديل النصّ
                      </Button>

                      {/* Retraction takes it off every screen holding it, which is
                          why «من أُبلغوا» falls to zero afterwards rather than
                          recording an audience that no longer holds anything. */}
                      <Button
                        variant="ghost"
                        onClick={() => act(() => announcements.hide(announcement.uuid))}
                        disabled={busy}
                      >
                        سحب الإعلان
                      </Button>
                    </div>
                  )
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
