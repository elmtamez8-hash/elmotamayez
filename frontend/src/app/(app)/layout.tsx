import type { Metadata } from "next";
import "../globals.css";
import { AuthProvider } from "@/lib/auth-context";

export const metadata: Metadata = {
  title: "Mteatch — Learning Platform",
  description: "Multi-tenant educational SaaS platform",
};

// Root layout for the authenticated product. The public marketplace ships its own
// root layout under (public) because it is Arabic/RTL; Next.js allows several root
// layouts as long as no app/layout.tsx sits above them.
export default function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body className="min-h-screen bg-gray-50 text-gray-900 antialiased">
        <AuthProvider>{children}</AuthProvider>
      </body>
    </html>
  );
}
