import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Tingan LabSmart",
  description: "面向科研团队的统一科研操作系统，融合 ELN、LIMS、分析、知识与 AI。",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="zh-CN" className="h-full antialiased">
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
