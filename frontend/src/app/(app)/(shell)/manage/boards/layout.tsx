import type { Metadata } from "next";

// The page is a client component, so its tab title lives here.
export const metadata: Metadata = { title: "السبّورات" };

export default function ManageBoardsLayout({ children }: { children: React.ReactNode }) {
  return children;
}
