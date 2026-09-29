import { act, renderHook, waitFor } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { type ListPage, usePagedList } from "./use-paged-list";

type Row = { uuid: string };

const page = (uuids: string[], current: number, last: number): ListPage<Row, undefined> => ({
  data: uuids.map((uuid) => ({ uuid })),
  meta: { total: 99, current_page: current, last_page: last },
});

describe("usePagedList", () => {
  it("offers no «عرض المزيد» while a new filter's page one is in flight", async () => {
    let resolveNew: (value: ListPage<Row, undefined>) => void = () => {};
    const oldFilter = (n: number) => Promise.resolve(page([`old-${n}`], n, 3));
    const newFilter = (n: number) =>
      n === 1
        ? new Promise<ListPage<Row, undefined>>((resolve) => {
            resolveNew = resolve;
          })
        : Promise.resolve(page([`new-${n}`], n, 3));

    const { result, rerender } = renderHook(({ fetch }) => usePagedList<Row>(fetch), {
      initialProps: { fetch: oldFilter },
    });

    await waitFor(() => expect(result.current.hasMore).toBe(true));

    rerender({ fetch: newFilter });
    await waitFor(() => expect(result.current.refreshing).toBe(true));

    expect(result.current.hasMore).toBe(false);
    act(() => result.current.loadMore());

    await act(async () => resolveNew(page(["new-1"], 1, 3)));

    await waitFor(() => expect(result.current.rows.map((row) => row.uuid)).toEqual(["new-1"]));
  });
});
