"use client";
import { useState, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useLanguage } from "@/context/LanguageContext";

export default function Navbar() {
  const { language, hydrated, setLanguage, t } = useLanguage();
  const pathname = usePathname();
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  // mounted: still needed for createPortal (document.body must exist)
  const [mounted, setMounted] = useState(false);
  // Drawer accessibility: remember what opened it, and where to trap focus.
  const drawerRef = useRef<HTMLDivElement>(null);
  const openerRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    setMounted(true);
  }, []);

  // Close mobile menu on route change
  useEffect(() => {
    setIsMobileMenuOpen(false);
  }, [pathname]);

  // Lock body scroll when mobile menu is open
  useEffect(() => {
    if (isMobileMenuOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isMobileMenuOpen]);

  /**
   * Modal-dialog accessibility for the drawer: Escape closes it, Tab is trapped
   * inside it, focus moves in on open and is restored to the trigger on close.
   * Without this, keyboard and screen-reader users tabbed straight past the
   * open drawer into the page behind it with no way to dismiss it.
   */
  useEffect(() => {
    if (!isMobileMenuOpen) return;

    const panel = drawerRef.current;
    // Move focus into the drawer.
    const focusables = () =>
      panel
        ? Array.from(
            panel.querySelectorAll<HTMLElement>(
              'a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])',
            ),
          ).filter((el) => el.offsetParent !== null)
        : [];
    focusables()[0]?.focus();

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        e.preventDefault();
        setIsMobileMenuOpen(false);
        return;
      }
      if (e.key === "Tab") {
        const items = focusables();
        if (items.length === 0) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };

    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      // Restore focus to the hamburger that opened the drawer.
      openerRef.current?.focus();
    };
  }, [isMobileMenuOpen]);

  return (
    <header className="w-full bg-white border-b border-[#E2E6ED] sticky top-0 z-50 supports-[backdrop-filter]:bg-white/95 supports-[backdrop-filter]:backdrop-blur-md">
      <div className="max-w-[1680px] 2xl:max-w-[1760px] mx-auto px-3 xs:px-4 sm:px-6 lg:px-8 2xl:px-10 h-16 xs:h-[4.5rem] sm:h-20 flex items-center justify-between gap-2 sm:gap-4">
        
        {/* Brand Logo with National Subtitle - Responsive scaling 320px -> 1920px+ */}
        <Link href="/" className="flex items-center gap-2 sm:gap-3 shrink-0 min-w-0">
          <div className="min-w-0">
            <span className="font-display font-black text-lg xs:text-xl sm:text-xl lg:text-2xl 2xl:text-[1.7rem] tracking-tight text-[#0F172A] block leading-none truncate">
              TENDER<span className="text-[#0055B8]">HUB</span>
            </span>
            <span className="text-[7px] xs:text-[8px] sm:text-[10px] font-bold text-gray-500 tracking-wider uppercase block mt-0.5 leading-tight truncate max-w-[140px] xs:max-w-[180px] sm:max-w-none">
              {t("brandSubtitle")}
            </span>
          </div>
        </Link>

        {/* Clean Desktop Navigation (xl and up) - Fluid gap scaling */}
        <nav className="hidden xl:flex items-center gap-4 2xl:gap-8 text-xs xl:text-sm font-bold text-[#374151] shrink-0">
          <Link
            href="/"
            className={`py-6 lg:py-7 transition-colors uppercase tracking-wider ${
              pathname === "/" || pathname === "/tenders-sri-lanka"
                ? "text-[#0055B8] font-extrabold border-b-2 border-[#0055B8]"
                : "text-gray-700 hover:text-[#0055B8]"
            }`}
          >
            {t("navCatalogue")}
          </Link>
          
          <Link
            href="/subscriber-pricing"
            className={`py-6 lg:py-7 transition-colors uppercase tracking-wider ${
              pathname === "/subscriber-pricing"
                ? "text-[#0055B8] font-extrabold border-b-2 border-[#0055B8]"
                : "text-gray-700 hover:text-[#0055B8]"
            }`}
          >
            {t("navPlans")}
          </Link>

          <Link
            href="/how-it-works"
            className={`py-6 lg:py-7 transition-colors uppercase tracking-wider ${
              pathname === "/how-it-works"
                ? "text-[#0055B8] font-extrabold border-b-2 border-[#0055B8]"
                : "text-gray-700 hover:text-[#0055B8]"
            }`}
          >
            {t("navHowItWorks")}
          </Link>

          <Link
            href="/about-us"
            className={`py-6 lg:py-7 transition-colors uppercase tracking-wider ${
              pathname === "/about-us"
                ? "text-[#0055B8] font-extrabold border-b-2 border-[#0055B8]"
                : "text-gray-700 hover:text-[#0055B8]"
            }`}
          >
            {t("navAbout")}
          </Link>

          <Link
            href="/contact-us"
            className={`py-6 lg:py-7 transition-colors uppercase tracking-wider ${
              pathname === "/contact-us"
                ? "text-[#0055B8] font-extrabold border-b-2 border-[#0055B8]"
                : "text-gray-700 hover:text-[#0055B8]"
            }`}
          >
            {t("navContact")}
          </Link>
        </nav>

        {/* Right Section: Interactive Trilingual Switcher + Desktop Doors + Mobile Toggle */}
        <div className="flex items-center gap-1.5 xs:gap-2 sm:gap-3.5 shrink-0">
          
          {/* Segmented Trilingual Language Switcher - Optimized for 320px -> 1920px */}
          <div className="flex items-center bg-[#F1F3F7] p-0.5 sm:p-1 rounded-lg sm:rounded-xl border border-[#E2E6ED] shadow-2xs shrink-0">
            <button 
              type="button"
              onClick={() => setLanguage("en")}
              aria-label="Switch to English"
              className={`px-2 sm:px-2.5 py-1.5 sm:py-1 text-[11px] sm:text-xs rounded-md sm:rounded-lg font-black transition-all cursor-pointer min-h-[36px] min-w-[36px] sm:min-h-0 sm:min-w-0 flex items-center justify-center ${
                hydrated && language === "en" ? "bg-white text-[#0055B8] shadow-xs" : "text-gray-600 hover:text-black font-bold"
              }`}
            >
              EN
            </button>
            <button 
              type="button"
              onClick={() => setLanguage("si")}
              aria-label="Switch to Sinhala"
              className={`px-2 sm:px-2.5 py-1.5 sm:py-1 text-[11px] sm:text-xs rounded-md sm:rounded-lg font-black transition-all cursor-pointer min-h-[36px] min-w-[36px] sm:min-h-0 sm:min-w-0 flex items-center justify-center ${
                hydrated && language === "si" ? "bg-white text-[#0055B8] shadow-xs" : "text-gray-600 hover:text-black font-bold"
              }`}
            >
              සිං
            </button>
            <button 
              type="button"
              onClick={() => setLanguage("ta")}
              aria-label="Switch to Tamil"
              className={`px-2 sm:px-2.5 py-1.5 sm:py-1 text-[11px] sm:text-xs rounded-md sm:rounded-lg font-black transition-all cursor-pointer min-h-[36px] min-w-[36px] sm:min-h-0 sm:min-w-0 flex items-center justify-center ${
                hydrated && language === "ta" ? "bg-white text-[#0055B8] shadow-xs" : "text-gray-600 hover:text-black font-bold"
              }`}
            >
              த
            </button>
          </div>

          {/* Desktop Auth State Doors (xl and up) */}
          <div className="hidden xl:flex items-center gap-2 lg:gap-2.5 shrink-0">
            {pathname.startsWith("/dashboard") || pathname.startsWith("/favorites") || pathname.startsWith("/related-tenders") || pathname.startsWith("/settings") ? (
              <Link
                href="/dashboard"
                className="bg-[#0055B8] hover:bg-[#004394] text-white text-xs font-black px-4 py-2 rounded-xl transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider shadow-md whitespace-nowrap flex items-center gap-2"
              >
                <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                <span>{t("navWorkspacePortal")}</span>
              </Link>
            ) : (
              <>
                <Link
                  href="/login"
                  className="text-xs font-bold text-[#0055B8] hover:text-[#004394] bg-[#EFF6FF] hover:bg-blue-100 px-3.5 py-2 rounded-xl transition-colors uppercase tracking-wider border border-[#BFDBFE] whitespace-nowrap"
                >
                  {t("navBidderLogin")}
                </Link>

                <Link
                  href="/register"
                  className="bg-[#0055B8] hover:bg-[#004394] text-white text-xs font-black px-4 py-2 rounded-xl transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider shadow-md whitespace-nowrap"
                >
                  {t("navCompanyWorkspace")}
                </Link>
              </>
            )}
          </div>

          {/* Mobile & Tablet Menu Hamburger Toggle (xl:hidden) - 44px touch target */}
          <button
            ref={openerRef}
            type="button"
            onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
            aria-label="Toggle Navigation Menu"
            aria-expanded={isMobileMenuOpen}
            aria-haspopup="dialog"
            aria-controls="mobile-nav-drawer"
            className="xl:hidden p-2.5 sm:p-2 rounded-xl bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 transition-all cursor-pointer min-h-[44px] min-w-[44px] flex items-center justify-center shrink-0"
          >
            {isMobileMenuOpen ? (
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" strokeWidth="2.5" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            ) : (
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" strokeWidth="2.5" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
              </svg>
            )}
          </button>

        </div>

      </div>

      {/* FULL OFF-CANVAS SLIDE-OVER SIDE MENU DRAWER (Portal to document.body for true full-screen overlay) */}
      {mounted && isMobileMenuOpen && createPortal(
        <div className="fixed inset-0 z-[99999] xl:hidden flex justify-end">
          {/* Backdrop */}
          <div 
            className="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity animate-fadeIn" 
            onClick={() => setIsMobileMenuOpen(false)}
            aria-hidden="true"
          />

          {/* Off-canvas Side Drawer Panel (Slide from Right, 100dvh full height) */}
          <div
            ref={drawerRef}
            id="mobile-nav-drawer"
            role="dialog"
            aria-modal="true"
            aria-label="Navigation menu"
            className="relative w-full max-w-[85vw] sm:max-w-sm h-[100dvh] max-h-[100dvh] bg-white shadow-2xl flex flex-col z-[100000] animate-slideLeft pb-[env(safe-area-inset-bottom,0px)]"
          >
            
            {/* Side Menu Header */}
            <div className="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between bg-[#0A1633] text-white shrink-0">
              <div className="min-w-0 pr-2">
                <span className="font-display font-black text-lg xs:text-xl tracking-tight block leading-none text-white">
                  TENDER<span className="text-[#38BDF8]">HUB</span>
                </span>
                <span className="text-[9px] font-bold text-slate-400 tracking-wider uppercase block mt-1 truncate">
                  {t("brandSubtitle")}
                </span>
              </div>

              <button
                type="button"
                onClick={() => setIsMobileMenuOpen(false)}
                aria-label="Close Side Menu"
                className="p-2 rounded-xl bg-white/10 hover:bg-white/20 text-white transition-all cursor-pointer shrink-0"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" strokeWidth="2.5" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Side Menu Body (Scrollable) */}
            <div className="flex-1 overflow-y-auto custom-scrollbar p-4 xs:p-5 space-y-6">
              
              {/* Trilingual Segmented Selector */}
              <div className="bg-slate-50 p-2.5 rounded-2xl border border-slate-200">
                <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-1.5 px-1">
                  Language / භාෂාව / மொழி
                </span>
                <div className="grid grid-cols-3 gap-1 bg-white p-1 rounded-xl border border-slate-200 shadow-2xs">
                  <button 
                    type="button"
                    onClick={() => setLanguage("en")}
                    className={`py-2 text-xs rounded-lg font-black transition-all cursor-pointer text-center ${
                      hydrated && language === "en" ? "bg-[#0055B8] text-white shadow-xs" : "text-slate-600 hover:text-black font-bold"
                    }`}
                  >
                    EN
                  </button>
                  <button 
                    type="button"
                    onClick={() => setLanguage("si")}
                    className={`py-2 text-xs rounded-lg font-black transition-all cursor-pointer text-center ${
                      hydrated && language === "si" ? "bg-[#0055B8] text-white shadow-xs" : "text-slate-600 hover:text-black font-bold"
                    }`}
                  >
                    සිං
                  </button>
                  <button 
                    type="button"
                    onClick={() => setLanguage("ta")}
                    className={`py-2 text-xs rounded-lg font-black transition-all cursor-pointer text-center ${
                      hydrated && language === "ta" ? "bg-[#0055B8] text-white shadow-xs" : "text-slate-600 hover:text-black font-bold"
                    }`}
                  >
                    த
                  </button>
                </div>
              </div>

              {/* Main Navigation Pages */}
              <div>
                <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-2 px-1">
                  Navigation
                </span>
                <nav className="flex flex-col space-y-1">
                  <Link
                    href="/"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`px-3.5 py-3 rounded-xl font-black text-xs sm:text-sm uppercase tracking-wider transition-all flex items-center justify-between min-h-[44px] ${
                      pathname === "/" || pathname === "/tenders-sri-lanka" ? "bg-[#EFF6FF] text-[#0055B8] shadow-2xs" : "text-slate-700 hover:bg-slate-50"
                    }`}
                  >
                    <span>{t("navCatalogue")}</span>
                    <span className="text-[10px] font-mono text-[#0055B8] bg-white px-2 py-0.5 rounded-md border border-[#BFDBFE]">39.9k</span>
                  </Link>

                  <Link
                    href="/subscriber-pricing"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`px-3.5 py-3 rounded-xl font-black text-xs sm:text-sm uppercase tracking-wider transition-all flex items-center justify-between min-h-[44px] ${
                      pathname === "/subscriber-pricing" ? "bg-[#EFF6FF] text-[#0055B8] shadow-2xs" : "text-slate-700 hover:bg-slate-50"
                    }`}
                  >
                    <span>{t("navPlans")}</span>
                    <span className="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">PRO</span>
                  </Link>

                  <Link
                    href="/how-it-works"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`px-3.5 py-3 rounded-xl font-black text-xs sm:text-sm uppercase tracking-wider transition-all flex items-center min-h-[44px] ${
                      pathname === "/how-it-works" ? "bg-[#EFF6FF] text-[#0055B8] shadow-2xs" : "text-slate-700 hover:bg-slate-50"
                    }`}
                  >
                    {t("navHowItWorks")}
                  </Link>

                  <Link
                    href="/about-us"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`px-3.5 py-3 rounded-xl font-black text-xs sm:text-sm uppercase tracking-wider transition-all flex items-center min-h-[44px] ${
                      pathname === "/about-us" ? "bg-[#EFF6FF] text-[#0055B8] shadow-2xs" : "text-slate-700 hover:bg-slate-50"
                    }`}
                  >
                    {t("navAbout")}
                  </Link>

                  <Link
                    href="/contact-us"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`px-3.5 py-3 rounded-xl font-black text-xs sm:text-sm uppercase tracking-wider transition-all flex items-center min-h-[44px] ${
                      pathname === "/contact-us" ? "bg-[#EFF6FF] text-[#0055B8] shadow-2xs" : "text-slate-700 hover:bg-slate-50"
                    }`}
                  >
                    {t("navContact")}
                  </Link>
                </nav>
              </div>

              {/* Special Gazette Spotlights */}
              <div>
                <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-2 px-1">
                  Gazette Spotlights
                </span>
                <div className="space-y-2">
                  <Link
                    href="/?category=suppliers"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between text-slate-800 transition-all hover:bg-slate-100 block"
                  >
                    <div>
                      <span className="text-[9px] font-black uppercase tracking-wider text-slate-400 block">{t("officialGazetteSpecialBadge")}</span>
                      <span className="text-xs font-black block">{t("spotlightSuppliers")}</span>
                    </div>
                    <span className="px-2 py-0.5 rounded-lg text-xs font-black font-mono bg-[#EFF6FF] text-[#0055B8]">
                      3,217
                    </span>
                  </Link>

                  <Link
                    href="/register"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className="p-3 rounded-xl bg-[#0F172A] text-white border border-slate-700 shadow-xs flex items-center justify-between transition-all block"
                  >
                    <div>
                      <span className="text-[9px] font-black uppercase tracking-wider text-blue-300 block">{t("forProcuringBodiesBadge")}</span>
                      <span className="text-xs font-black block">{t("publishFreeTitle")}</span>
                    </div>
                    <span className="px-2.5 py-1 rounded-lg bg-[#0055B8] text-white text-[10px] font-black uppercase tracking-wider shrink-0">
                      + FREE
                    </span>
                  </Link>
                </div>
              </div>

            </div>

            {/* Side Menu Footer Action Doors */}
            <div className="p-4 border-t border-slate-100 bg-slate-50 flex flex-col gap-2 shrink-0">
              {pathname.startsWith("/dashboard") || pathname.startsWith("/favorites") || pathname.startsWith("/related-tenders") || pathname.startsWith("/settings") ? (
                <Link
                  href="/dashboard"
                  onClick={() => setIsMobileMenuOpen(false)}
                  className="w-full text-center bg-[#0055B8] hover:bg-[#004394] text-white text-xs font-black py-3.5 rounded-xl uppercase tracking-wider shadow-md flex items-center justify-center gap-2 min-h-[44px] active:scale-[0.98]"
                >
                  <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                  <span>{t("navWorkspacePortal")}</span>
                </Link>
              ) : (
                <div className="grid grid-cols-2 gap-2">
                  <Link
                    href="/login"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className="w-full text-center text-xs font-bold text-[#0055B8] bg-white py-3 rounded-xl uppercase tracking-wider border border-[#BFDBFE] min-h-[44px] flex items-center justify-center shadow-2xs active:bg-blue-50"
                  >
                    {t("navBidderLogin")}
                  </Link>
                  <Link
                    href="/register"
                    onClick={() => setIsMobileMenuOpen(false)}
                    className="w-full text-center bg-[#0055B8] hover:bg-[#004394] text-white text-xs font-black py-3 rounded-xl uppercase tracking-wider shadow-md min-h-[44px] flex items-center justify-center active:scale-98"
                  >
                    {t("navCompanyWorkspace")}
                  </Link>
                </div>
              )}
            </div>

          </div>
        </div>,
        document.body
      )}
    </header>
  );
}
