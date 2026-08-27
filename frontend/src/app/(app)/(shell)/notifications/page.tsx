"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { categoryIcon } from "@/components/notifications/categoryIcons";
import { NotificationRow } from "@/components/notifications/NotificationRow";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Tabs, TabPanel, useTabParam, type TabDefinition } from "@/components/ui/Tabs";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
import { relativeDayLabel } from "@/lib/labels";
import {
  notifications,
  type NotificationCategory,
  type NotificationItem,
} from "@/lib/notifications";

/**
 * The notification centre.
 *
 * ⚠️ THE PROBLEM WAS NEVER THE ORDER, IT WAS THE WALL. One demo student carries
 * sixty-four unread, and their first page alone holds seven different kinds —
 * session reports, badges, a graded assignment, a chat message — rendered
 * identically, one under the next. «Which of these is about my lessons» had no
 * answer short of reading all of them.
 *
 * ⚠️ THE TABS ARE SUBJECTS, NOT TYPES. There are forty-eight types; a picker
 * with forty-eight options is a second list to read, and most of them belong to
 * a role the reader does not have. `NotificationCategory` folds them into seven,
 * and the SERVER decides which of the seven this reader actually has — a student
 * receives no settlement notice and a teacher no guardian-consent request, so a
 * fixed strip would hand each of them a control that empties the page. Same rule
 * the mistake notebook's filter bar follows, and the one spec 009's leaderboard
 * picker was fixed under.
 *
 * ⚠️ AND THE COUNTS ARE THE POINT, NOT THE HIDING. Without them the tabs are
 * seven guesses; with them the strip says where the sixty-four are before the
 * reader presses anything.
 */

/** «الكل» is not a category — it is the absence of one. */
const ALL = "all";

type Group = { key: string; label: string; items: NotificationItem[] };

/** The page's rows, cut into days in one pass. The feed is already newest first. */
function byDay(items: NotificationItem[]): Group[] {
  const groups: Group[] = [];

  for (const item of items) {
    const label = relativeDayLabel(item.created_at);
    const last = groups.at(-1);

    if (last !== undefined && last.label === label) {
      last.items.push(item);
      continue;
    }

    groups.push({ key: `${label}-${item.uuid}`, label, items: [item] });
  }

  return groups;
}

export default function NotificationsPage() {
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [unread, setUnread] = useState(0);
  const [categories, setCategories] = useState<NotificationCategory[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [onlyUnread, setOnlyUnread] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  /*
    ⚠️ THE STRIP IS BUILT FROM THE SERVER'S ANSWER, AND `useMemo` IS NOT AN
    OPTIMISATION HERE. `useTabParam` re-runs its effect whenever this array's
    identity changes, so a fresh array on every render would fight the `?tab=`
    the reader typed or shared.
  */
  const tabs = useMemo<TabDefinition[]>(
    () => [
      { key: ALL, label: "الكل", badge: unread, icon: categoryIcon(null) },
      ...categories.map((category) => ({
        key: category.key,
        label: category.label,
        badge: category.unread,
        // Emphasis on top of the word — the label stays and the glyph is
        // `aria-hidden`, so nothing here is carried by the picture alone.
        icon: categoryIcon(category.key),
      })),
    ],
    [categories, unread],
  );

  const [active, selectTab] = useTabParam(tabs);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const result = await notifications.list({
        page,
        unread: onlyUnread,
        category: active === ALL ? undefined : active,
      });

      setItems(result.data);
      setUnread(result.meta.unread_count);
      setCategories(result.meta.categories ?? []);
      setLastPage(result.meta.last_page);
    } catch (err) {
      // Never the raw error: it is an English developer string.
      setError(errorMessage(err, "تعذّر تحميل الإشعارات."));
    } finally {
      setLoading(false);
    }
  }, [page, onlyUnread, active]);

  useEffect(() => {
    void load();
  }, [load]);

  // Changing what is being read starts at its first page — page 3 of «الحصص» is
  // not page 3 of «الكل», and keeping the number shows an empty list.
  const openTab = (key: string) => {
    setPage(1);
    selectTab(key);
  };

  const markRead = async (uuid: string) => {
    try {
      const { unread_count } = await notifications.markRead(uuid);

      setUnread(unread_count);
      setItems((current) =>
        current.map((item) =>
          item.uuid === uuid ? { ...item, read_at: new Date().toISOString() } : item,
        ),
      );

      /*
        ⚠️ THE TAB'S OWN COUNT MOVES TOO. Left alone, the strip keeps claiming a
        number the list under it no longer shows — the drift the bell had before
        the request layer started announcing reads. The subject comes off the ROW
        rather than being assumed to be the open tab: under «الكل» the row being
        read belongs to whichever subject it belongs to, and the map that answers
        that is the server's, not a copy of it here.
      */
      const subject = items.find((item) => item.uuid === uuid)?.category?.key;

      if (subject !== undefined) {
        setCategories((current) =>
          current.map((category) =>
            category.key === subject
              ? { ...category, unread: Math.max(0, category.unread - 1) }
              : category,
          ),
        );
      }
    } catch (err) {
      setError(errorMessage(err, "تعذّر تعليم الإشعار مقروءاً."));
    }
  };

  const markAllRead = async () => {
    try {
      await notifications.markAllRead();

      setUnread(0);
      setCategories((current) => current.map((category) => ({ ...category, unread: 0 })));
      setItems((current) =>
        current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })),
      );
    } catch (err) {
      setError(errorMessage(err, "تعذّر تعليم الكل مقروءاً."));
    }
  };

  const openCategory = categories.find((category) => category.key === active);

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">الإشعارات</h1>
          <p className="text-sm text-ink-muted">
            {unread > 0
              ? `لديك ${unread.toLocaleString("ar-EG")} إشعاراً غير مقروء`
              : "لا إشعارات غير مقروءة"}
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              setPage(1);
              setOnlyUnread((value) => !value);
            }}
          >
            {onlyUnread ? "عرض الكل" : "غير المقروء فقط"}
          </Button>
          <Button size="sm" onClick={markAllRead} disabled={unread === 0}>
            تعليم الكل مقروءاً
          </Button>
        </div>
      </header>

      {/*
        One tab is «الكل» and the rest are the subjects this reader has. A strip
        of one is no strip at all — a reader whose whole feed is one subject is
        offered nothing to press.
      */}
      {tabs.length > 1 && (
        <Tabs tabs={tabs} active={active} onChange={openTab} label="أقسام الإشعارات" />
      )}

      {error !== "" && <Alert tone="danger" title={error} />}

      <TabPanel tabKey={active} active={active}>
        {loading ? (
          <RowsSkeleton count={4} />
        ) : items.length === 0 ? (
          <EmptyState
            title={
              onlyUnread
                ? "لا إشعارات غير مقروءة هنا"
                : openCategory !== undefined
                  ? `لا إشعارات في «${openCategory.label}»`
                  : "لا إشعارات بعد"
            }
            description={
              onlyUnread
                ? "قرأت كلّ ما وصلك. اضغط «عرض الكل» لمراجعتها."
                : "يظهر هنا كلّ ما يصلك من مدرّسيك ومن المنصّة."
            }
          />
        ) : (
          <div className="space-y-6">
            {byDay(items).map((group) => (
              <section key={group.key} className="space-y-3">
                {/* A rule to the end of the row, so the eye finds where one day
                    stops without a second border competing with the rows'. */}
                <h2 className="flex items-center gap-3 text-sm font-semibold text-ink-muted">
                  <span>{group.label}</span>
                  <span aria-hidden className="h-px flex-1 bg-line" />
                </h2>

                <ul className="space-y-3">
                  {group.items.map((item, index) => (
                    <li
                      key={item.uuid}
                      className="banner-rise"
                      // Capped: a page of twenty times a per-row delay lands the
                      // last row a second late, and `globals.css` zeroes all of
                      // it for a reader who asked for less motion.
                      style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
                    >
                      <NotificationRow item={item} onRead={markRead} />
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
        )}
      </TabPanel>

      {lastPage > 1 && (
        <div className="flex items-center justify-center gap-3">
          <Button
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((value) => value - 1)}
          >
            السابق
          </Button>
          <span className="text-sm text-ink-muted">
            {page.toLocaleString("ar-EG")} / {lastPage.toLocaleString("ar-EG")}
          </span>
          <Button
            variant="secondary"
            size="sm"
            disabled={page >= lastPage}
            onClick={() => setPage((value) => value + 1)}
          >
            التالي
          </Button>
        </div>
      )}
    </div>
  );
}
