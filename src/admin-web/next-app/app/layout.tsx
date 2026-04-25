import type { Metadata } from "next";
import { NEXT_PUBLIC_BASE_PATH, withBasePath } from "@/lib/base-path";
import { PwaRegister } from "@/components/pwa-register";
import "./globals.css";
import "./auth.css";
import "./shell.css";
import "./dashboard.css";
import "./tables.css";
import "./forms.css";
import "./domain.css";
import "./motion.css";
import "./responsive.css";

export const metadata: Metadata = {
  title: "勤怠管理システム",
  description: "職員打刻・休暇申請・給与明細配信アプリ 管理Web",
  manifest: withBasePath("/manifest.webmanifest"),
  icons: {
    icon: [
      { url: withBasePath("/icon-192.png"), sizes: "192x192", type: "image/png" },
      { url: withBasePath("/icon-512.png"), sizes: "512x512", type: "image/png" },
      { url: withBasePath("/icon.svg"), type: "image/svg+xml" },
    ],
    shortcut: [{ url: withBasePath("/icon-192.png"), sizes: "192x192", type: "image/png" }],
    apple: [{ url: withBasePath("/apple-touch-icon.png"), sizes: "180x180", type: "image/png" }],
  },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="ja">
      <body data-base-path={NEXT_PUBLIC_BASE_PATH}>
        <PwaRegister />
        {children}
      </body>
    </html>
  );
}
