"use client";

import { useEffect, useState } from "react";

/**
 * The inline script in the layout has already set data-theme before paint; this
 * only reflects and changes it. Reading the attribute rather than keeping separate
 * state avoids the two disagreeing after hydration.
 */
export function ThemeToggle() {
  const [theme, setTheme] = useState<"light" | "dark">("light");

  useEffect(() => {
    setTheme(document.documentElement.dataset.theme === "dark" ? "dark" : "light");
  }, []);

  const toggle = () => {
    const next = theme === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = next;
    localStorage.setItem("theme", next);
    setTheme(next);
  };

  return (
    <button
      type="button"
      onClick={toggle}
      className="rounded-lg p-2 text-ink transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      aria-label={theme === "dark" ? "التبديل إلى الوضع الفاتح" : "التبديل إلى الوضع الليلي"}
    >
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
        {theme === "dark" ? (
          <circle cx="12" cy="12" r="4" />
        ) : (
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"
          />
        )}
      </svg>
    </button>
  );
}
