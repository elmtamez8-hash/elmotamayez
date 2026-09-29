"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/** The paginator envelope both staff lists answer with. */
export interface ListPage<T, C> {
  data: T[];
  meta?: { total?: number; current_page?: number; last_page?: number; counts?: C };
}

/**
 * A staff list that is paged, searched and filtered ON THE SERVER, with
 * «عرض المزيد» appending the next page.
 *
 * `fetchPage` must be memoised on the filters (`useCallback([q, status])`): a new
 * function IS a new query, and the list restarts from page one.
 *
 * ⚠️ ONLY THE LATEST REQUEST MAY WRITE. A debounced search still sends one
 * request per pause, and an older, slower answer landing after a newer one would
 * put the rows for «الكس» under a box that says «الكسور». Every call takes a
 * ticket and an answer whose ticket is stale is dropped.
 *
 * ⚠️ APPENDED, NEVER REPLACED, AND DE-DUPLICATED BY UUID. A row created while the
 * reader is on page one shifts every later page by one, so page two repeats the
 * last row of page one.
 */
export function usePagedList<T extends { uuid: string }, C = undefined>(
  fetchPage: (page: number) => Promise<ListPage<T, C>>,
) {
  const [rows, setRows] = useState<T[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  // True once any answer has arrived: the filter bar stays mounted from then on,
  // so a search that empties the list can still be cleared.
  const [settled, setSettled] = useState(false);
  // True once any page carried a row. Sticky, so a chip that empties the list
  // and the press that widens it again do not unmount the bar under the cursor.
  const [everFilled, setEverFilled] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState<number | null>(null);
  const [counts, setCounts] = useState<C | undefined>(undefined);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreFailed, setMoreFailed] = useState(false);
  const latest = useRef(0);

  const load = useCallback(
    async (target: number) => {
      const ticket = ++latest.current;

      if (target === 1) {
        setRefreshing(true);
      } else {
        setLoadingMore(true);
      }

      setMoreFailed(false);

      try {
        const response = await fetchPage(target);

        if (ticket !== latest.current) return;

        const incoming = response.data ?? [];

        if (incoming.length > 0) setEverFilled(true);

        setRows((current) => {
          if (target === 1) return incoming;

          const seen = new Set(current.map((row) => row.uuid));

          return [...current, ...incoming.filter((row) => !seen.has(row.uuid))];
        });
        setPage(response.meta?.current_page ?? target);
        setLastPage(response.meta?.last_page ?? 1);
        setTotal(typeof response.meta?.total === "number" ? response.meta.total : null);
        setCounts(response.meta?.counts);
        setState("ready");
        setSettled(true);
      } catch {
        if (ticket !== latest.current) return;

        // A failed first page takes the list to its error state; a failed
        // «عرض المزيد» keeps every row already on screen and says so beside it.
        if (target === 1) setState("error");
        else setMoreFailed(true);
      } finally {
        if (ticket === latest.current) {
          setRefreshing(false);
          setLoadingMore(false);
        }
      }
    },
    [fetchPage],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  return {
    rows,
    state,
    settled,
    everFilled,
    refreshing,
    total,
    counts,
    hasMore: page < lastPage,
    loadingMore,
    moreFailed,
    reload: useCallback(() => load(1), [load]),
    loadMore: () => load(page + 1),
  };
}
