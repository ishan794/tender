import type { Metadata } from "next";
import { Plus_Jakarta_Sans, Barlow_Condensed } from "next/font/google";
import "./globals.css";
import { ToastProvider } from "@/components/ui/Toaster";
import { LanguageProvider } from "@/context/LanguageContext";

const barlowCondensed = Barlow_Condensed({
  weight: ["600", "700", "800"],
  subsets: ["latin"],
  variable: "--font-display",
});

const plusJakartaSans = Plus_Jakarta_Sans({
  subsets: ["latin"],
  variable: "--font-sans",
  weight: ["400", "500", "600", "700", "800"],
});

export const metadata: Metadata = {
  title: "TenderHub Sri Lanka — National Procurement & Tender Network",
  description: "Sri Lanka's centralized commercial and state procurement gateway. Aggregating national gazettes, government ministries, and verified corporate RFPs daily.",
  metadataBase: new URL("https://tenderhub.lk"),
  alternates: {
    canonical: "/",
    languages: {
      "en": "/",
      "si": "/?lang=si",
      "ta": "/?lang=ta",
      "x-default": "/",
    },
  },
  openGraph: {
    title: "TenderHub Sri Lanka — National Procurement & Tender Network",
    description: "Sri Lanka's centralized commercial and state procurement gateway. Aggregating national gazettes, government ministries, and verified corporate RFPs daily.",
    url: "https://tenderhub.lk",
    siteName: "TenderHub Sri Lanka",
    locale: "en_LK",
    type: "website",
  },
  twitter: {
    card: "summary_large_image",
    title: "TenderHub Sri Lanka — National Procurement & Tender Network",
    description: "Sri Lanka's centralized commercial and state procurement gateway.",
  },
};

export const viewport = {
  width: "device-width",
  initialScale: 1,
  maximumScale: 5,
  viewportFit: "cover",
  themeColor: "#0055B8",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" suppressHydrationWarning className={`${barlowCondensed.variable} ${plusJakartaSans.variable}`}>
      <body suppressHydrationWarning className="antialiased min-h-screen min-h-[100dvh] flex flex-col bg-white text-[#111827] overflow-x-clip">
        {/*
          Navbar and Footer are NOT rendered here. They belong to the marketing
          site and now live in (public)/layout.tsx. Rendering them at the root
          put the public header and footer inside every portal, console and
          workspace page — two stacked headers with two brand lockups above the
          admin tables, and the marketing footer below them.
        */}
        <ToastProvider>
          <LanguageProvider>{children}</LanguageProvider>
        </ToastProvider>
      </body>
    </html>
  );
}
