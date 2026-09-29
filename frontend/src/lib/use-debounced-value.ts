"use client";

import { useEffect, useState } from "react";

/**
 * `value`, once it has stopped changing for `delay` ms.
 *
 * For a search box that asks the SERVER: without it every keystroke is a request,
 * and «الكسور» typed is six of them racing each other back.
 */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay]);

  return settled;
}
