"use client";

import { useCallback, useEffect, useState } from "react";

import { AnnouncementForm } from "@/components/community/AnnouncementForm";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { FilterBar } from "@/components/ui/FilterBar";
import { PageHeader } from "@/components/ui/PageHeader";
import { RecordList, RecordRow } from "@/components/ui/RecordList";
import { SectionHeading } from "@/components/ui/SectionHeading";
import {
  BellIcon,
  EditIcon,
  EyeIcon,
  HistoryIcon,
  PublishIcon,
  ScheduleIcon,
  SparkIcon,
  UsersIcon,
} from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import {
  ANNOUNCEMENT_SCOPES,
  announcements,
  hasMoreAnnouncements,
  type Announcement,
  type AnnouncementInput,
} from "@/lib/announcements";
import { classSessions } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { counted, formatDate } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";

/** «١٢ إعلاناً» — not in `NOUNS` yet; one screen counts them. */
const ANNOUNCEMENTS = {
  one: "إعلان واحد",
  two: "إعلانان",
  few: "إعلانات",
  many: "إعلاناً",
  other: "إعلان",
};

type VisibilityFilter = "all" | "visible" | "hidden";

/** The row's heading: who it went to. The body is the row's text, shown once. */
function audienceTitle(announcement: Announcement): string {
  const label = ANNOUNCEMENT_SCOPES.find((option) => option.key === announcement.scope)?.label;

  return label !== undefined ? `إلى ${label}` : "إعلان";
}

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
 *
 * Laid out on the staff kit (`docs/design/manage-pages.md`). The chips carry no
 * counts: the list is paginated (50 a page), so a count summed here would be the
 * pages already read, not the teacher's announcements. The heading's number is
 * the server's `meta.total` when it sends one.
 */
export default function AnnouncementsPage() {
  const [rows, setRows] = useState<Announcement[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [courses, setCourses] = useState<Array<{ uuid: string; title: string }>>([]);
  const [sessions, setSessions] = useState<Array<{ uuid: string; title: string }>>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState(false);
  // The create form's failure, under the form; a row action's, above the list.
  const [error, setError] = useState<string | null>(null);
  const [rowError, setRowError] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [visibility, setVisibility] = useState<VisibilityFilter>("all");
  // The list is paginated (50 a page): the last page read, and whether another exists.
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);

  // Back to page one — after every write too, so a new or edited notice is
  // never hiding behind pages the reader had already opened.
  const load = useCallback(() => {
    setState("loading");
    setMoreError(null);

    announcements
      .list(1)
      .then((response) => {
        setRows(response.data ?? []);
        setTotal(typeof response.meta?.total === "number" ? response.meta.total : null);
        setPage(1);
        setHasMore(hasMoreAnnouncements(response));
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  async function loadMore() {
    setLoadingMore(true);
    setMoreError(null);

    try {
      const response = await announcements.list(page + 1);
      const seen = new Set(rows.map((row) => row.uuid));

      // A notice published meanwhile shifts every page by one; the uuid check
      // keeps the row that slid across the boundary from appearing twice.
      setRows([...rows, ...(response.data ?? []).filter((row) => !seen.has(row.uuid))]);
      setPage(page + 1);
      setHasMore(hasMoreAnnouncements(response));
    } catch (err) {
      setMoreError(userMessage(err));
    } finally {
      setLoadingMore(false);
    }
  }

  useEffect(load, [load]);

  useEffect(() => {
    // The two pickers. A failure here leaves the scope selector with an empty
    // list rather than breaking the page — a notice to every student needs neither.
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
    setRowError(null);

    try {
      await work();
      setEditing(null);
      load();
    } catch (err) {
      setRowError(userMessage(err));
    } finally {
      setBusy(false);
    }
  }

  const shown = rows.filter((announcement) =>
    visibility === "all"
      ? true
      : visibility === "hidden"
        ? announcement.is_hidden
        : !announcement.is_hidden,
  );

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={BellIcon}
        title="الإعلانات"
        description="إعلان واحد يصل من اخترتهم وحدهم. يقرأه الطالب في مركز الإشعارات، ويردّ عليك في محادثته الخاصة إن أراد."
      />

      <Card as="section">
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

      <section aria-labelledby="sent-announcements" className="space-y-4">
        <SectionHeading
          id="sent-announcements"
          Icon={HistoryIcon}
          title="ما أعلنته"
          description={
            state === "ready" && total !== null && total > 0
              ? counted(total, ANNOUNCEMENTS)
              : undefined
          }
        />

        {state === "loading" && <RowsSkeleton count={3} />}

        {state === "error" && <ErrorState onRetry={load} />}

        {state === "ready" && rows.length === 0 && (
          <EmptyState
            Icon={BellIcon}
            title="لا توجد إعلانات بعد"
            description="اكتب أوّل إعلان من النموذج أعلاه. يظهر هنا مع عدد من وصلهم وعدد من قرأوه."
          />
        )}

        {state === "ready" && rows.length > 0 && (
          <>
            <FilterBar
              filters={{
                label: "حالة الإعلان",
                value: visibility,
                onChange: (key) => setVisibility(key as VisibilityFilter),
                options: [
                  { key: "all", label: "الكل" },
                  { key: "visible", label: "الظاهرة" },
                  { key: "hidden", label: "المسحوبة" },
                ],
              }}
            />

            {rowError !== null && <Alert tone="danger" title="تعذّر الحفظ">{rowError}</Alert>}

            {shown.length === 0 ? (
              <EmptyState
                title={visibility === "hidden" ? "لا إعلانات مسحوبة هنا" : "لا إعلانات ظاهرة هنا"}
                description="لا إعلان بهذه الحالة بين المعروض الآن."
                action={
                  <Button variant="secondary" onClick={() => setVisibility("all")}>
                    عرض الكل
                  </Button>
                }
              />
            ) : (
              <RecordList labelledBy="sent-announcements">
                {shown.map((announcement) => {
                  const when = announcement.published_at ?? announcement.created_at;
                  const isEditing = editing === announcement.uuid;

                  return (
                    <RecordRow
                      key={announcement.uuid}
                      level={4}
                      Icon={BellIcon}
                      tone={
                        announcement.is_hidden
                          ? "neutral"
                          : announcement.is_urgent
                            ? "danger"
                            : "info"
                      }
                      title={audienceTitle(announcement)}
                      status={
                        announcement.is_hidden ? (
                          <Badge tone="neutral">مسحوب</Badge>
                        ) : (
                          <>
                            {announcement.is_urgent && <Badge tone="danger">عاجل</Badge>}
                            {!announcement.is_published && <Badge tone="neutral">مسوّدة</Badge>}
                          </>
                        )
                      }
                      description={<p className="whitespace-pre-line text-ink">{announcement.body}</p>}
                      meta={[
                        {
                          key: "notified",
                          label: "وصلهم",
                          Icon: UsersIcon,
                          value: arabicNumber(announcement.notified_count ?? 0),
                        },
                        {
                          key: "read",
                          label: "قرأوه",
                          Icon: EyeIcon,
                          value: arabicNumber(announcement.read_count ?? 0),
                        },
                        ...(when !== null
                          ? [{ key: "date", label: "التاريخ", labelHidden: true, Icon: ScheduleIcon, value: formatDate(when) }]
                          : []),
                      ]}
                      actions={
                        !isEditing && !announcement.is_hidden ? (
                          <>
                            {!announcement.is_published && (
                              // A draft left behind by a publish that failed. Without
                              // this there is no way to ever send it.
                              <Button
                                size="sm"
                                variant="primary"
                                iconStart={<PublishIcon />}
                                onClick={() => act(() => announcements.publish(announcement.uuid))}
                                disabled={busy}
                              >
                                نشر
                              </Button>
                            )}

                            {/* FR-047 — a correction reaches everyone already holding
                                it, because the notification IS the delivery. */}
                            <Button
                              size="sm"
                              variant="ghost"
                              iconStart={<EditIcon />}
                              onClick={() => setEditing(announcement.uuid)}
                              disabled={busy}
                            >
                              تعديل النصّ
                            </Button>

                            {/* Retraction takes it off every screen holding it, which is
                                why «من أُبلغوا» falls to zero afterwards rather than
                                recording an audience that no longer holds anything. And
                                nothing here puts it back, so the press arms first. */}
                            <ConfirmButton
                              size="sm"
                              variant="secondary"
                              disabled={busy}
                              confirmLabel="اضغط مجدداً لسحبه"
                              onConfirm={() => act(() => announcements.hide(announcement.uuid))}
                            >
                              سحب الإعلان
                            </ConfirmButton>
                          </>
                        ) : undefined
                      }
                    >
                      {isEditing ? (
                        <div className="banner-rise space-y-2 rounded-2xl bg-surface p-4">
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
                          <Button variant="ghost" onClick={() => setEditing(null)} disabled={busy}>
                            إلغاء التعديل
                          </Button>
                        </div>
                      ) : undefined}
                    </RecordRow>
                  );
                })}
              </RecordList>
            )}

            {hasMore && (
              <div className="flex flex-col items-center gap-2">
                {moreError !== null && <Alert tone="danger" title={moreError} />}
                <Button variant="secondary" onClick={loadMore} loading={loadingMore}>
                  عرض المزيد
                </Button>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  );
}
