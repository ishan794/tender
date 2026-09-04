"use client";
import { useState, useEffect, Suspense } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { MOCK_TENDERS, TenderItem } from "@/data/tenders";
import { useToast } from "@/components/ui/Toaster";
import { useLanguage } from "@/context/LanguageContext";

type DashboardView = "overview" | "related" | "favorites" | "settings";

function SupplierDashboardContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const toast = useToast();
  const { t } = useLanguage();

  const tabParam = searchParams.get("tab") as DashboardView | null;
  const [activeView, setActiveView] = useState<DashboardView>(tabParam || "overview");

  useEffect(() => {
    router.replace("/app");
  }, [router]);

  // User Profile State
  const [userProfile, setUserProfile] = useState({
    name: "Kamal Perera",
    company: "Perera Engineering & Infrastructure (Pvt) Ltd",
    brn: "PV-8849201",
    cidaGrade: "CIDA Grade C3 (Civil & Electro-Mechanical)",
    email: "kamal@pereraengineering.lk",
    phone: "+94 77 388 7615",
    preferredCategory: "Civil Construction & Works",
    whatsappAlerts: true,
    emailDigest: "daily",
  });

  // Watchlist / Bookmarks state
  const [watchlist, setWatchlist] = useState<string[]>([
    "SLPA-2026-PT-04",
    "RDA-2026-KY-044",
    "MOE-2026-SP-01",
  ]);

  // Sector filter for Related Tenders
  const [selectedSector, setSelectedSector] = useState("all");
  const [searchQuery, setSearchQuery] = useState("");

  useEffect(() => {
    if (tabParam && ["overview", "related", "favorites", "settings"].includes(tabParam)) {
      setActiveView(tabParam);
    }
  }, [tabParam]);

  const handleLogout = () => {
    document.cookie = "tenderhub_auth=; path=/; expires=Thu, 01 Jan 1970 00:00:01 GMT;";
    toast.info("Logged Out", "You have been safely signed out of your supplier workspace.");
    setTimeout(() => {
      router.push("/login");
    }, 600);
  };

  const toggleBookmark = (id: string, refCode: string) => {
    if (watchlist.includes(id)) {
      setWatchlist((prev) => prev.filter((item) => item !== id));
      toast.info("Watchlist Updated", `Notice ${refCode} removed from your watchlist.`);
    } else {
      setWatchlist((prev) => [...prev, id]);
      toast.success("Saved to Watchlist", `Notice ${refCode} added to your procurement watchlist.`);
    }
  };

  const handleSaveSettings = (e: React.FormEvent) => {
    e.preventDefault();
    toast.success("Profile Updated", "Company credentials & notification preferences saved successfully.");
  };

  // Filtered tenders for Related view
  const relatedTenders = MOCK_TENDERS.filter((tender) => {
    const matchesSearch =
      searchQuery === "" ||
      tender.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      tender.entity.toLowerCase().includes(searchQuery.toLowerCase()) ||
      tender.ref.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesSector =
      selectedSector === "all" || tender.categoryId.toLowerCase() === selectedSector.toLowerCase();
    return matchesSearch && matchesSector;
  });

  // Watched tenders for Favorites view
  const favoriteTenders = MOCK_TENDERS.filter((tender) => watchlist.includes(tender.id));

  return (
    <div className="min-h-screen bg-[#F8FAFC] py-8">
      <div className="max-w-[1680px] mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        
        {/* 1. EXECUTIVE HERO BOX (Box-wise Architectural Card with Context Image & Ample Breathing Room) */}
        <section className="relative rounded-3xl shadow-2xl bg-[#0A1633] text-white border border-slate-800 overflow-hidden">
          
          {/* Context Architectural Engineering Image Container (Rule #3) */}
          <div className="absolute inset-0 pointer-events-none">
            <div 
              className="absolute inset-0 bg-cover bg-center opacity-40 scale-105 transition-transform duration-1000"
              style={{
                backgroundImage: `url('https://images.unsplash.com/photo-1541888946425-d0fbb186156f?q=80&w=2000&auto=format&fit=crop')`,
              }}
            />
            <div className="absolute inset-0 bg-linear-to-r from-[#07132F]/95 via-[#0A1E4A]/80 to-[#07132F]/90" />
          </div>

          {/* Hero Content Grid */}
          <div className="relative z-10 p-6 sm:p-10 lg:p-12">
            
            {/* Top Verification & Plan Bar */}
            <div className="flex flex-wrap items-center justify-between gap-4 pb-6 mb-6 border-b border-white/15 text-xs">
              <div className="flex items-center gap-2.5">
                <span className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse shadow-sm" />
                <span className="text-[11px] font-black uppercase tracking-widest text-blue-200">
                  {t("dashVerifiedWorkspace")}
                </span>
                <span className="text-slate-500">&bull;</span>
                <span className="font-mono text-slate-300 font-bold text-[11px]">
                  BRN: {userProfile.brn}
                </span>
              </div>

              <div className="flex items-center gap-3">
                <span className="px-3.5 py-1.5 bg-white/10 border border-white/20 rounded-xl text-xs font-black text-white">
                  {t("dashBusinessActive")}
                </span>
                <button
                  type="button"
                  onClick={handleLogout}
                  className="px-3.5 py-1.5 bg-white/10 hover:bg-white text-white hover:text-[#0F172A] border border-white/20 font-black text-xs rounded-xl transition-all hover:-translate-y-0.5 active:scale-95 cursor-pointer uppercase tracking-wider"
                >
                  {t("dashSignOut")}
                </button>
              </div>
            </div>

            {/* Main Company Title & Quick Actions Row */}
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
              <div className="space-y-2 max-w-3xl">
                <span className="text-[11px] font-black uppercase tracking-widest text-blue-300 block">
                  {t("dashRegisteredContractor")}
                </span>
                <h1 className="font-display text-2xl sm:text-3xl lg:text-4xl font-black text-white tracking-tight uppercase leading-tight">
                  {userProfile.company}
                </h1>
                <div className="flex flex-wrap items-center gap-2 text-xs text-blue-100 font-medium">
                  <span>{t("dashOfficerInCharge")} <strong className="text-white font-bold">{userProfile.name}</strong></span>
                  <span className="text-slate-400">&bull;</span>
                  <span>{userProfile.cidaGrade}</span>
                  <span className="text-slate-400">&bull;</span>
                  <span>{t("dashPrimaryTrade")} <strong className="text-white font-bold">{userProfile.preferredCategory}</strong></span>
                </div>
              </div>

              {/* Quick Jump Action Pills */}
              <div className="flex flex-wrap items-center gap-2.5 shrink-0">
                <button
                  type="button"
                  onClick={() => setActiveView("related")}
                  className="px-5 py-3 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-md transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider cursor-pointer flex items-center gap-1.5"
                >
                  <span>{t("dashSearchBids")}</span>
                  <span>&rarr;</span>
                </button>
                <button
                  type="button"
                  onClick={() => setActiveView("favorites")}
                  className="px-5 py-3 bg-white/10 hover:bg-white text-white hover:text-[#0F172A] border border-white/20 font-black text-xs rounded-xl transition-all active:scale-95 cursor-pointer uppercase tracking-wider"
                >
                  {t("dashWatchlist")} ({watchlist.length})
                </button>
              </div>
            </div>

          </div>
        </section>

        {/* 2. STRAIGHT 4-BOX METRICS RAIL (Box-wise Linear Grid with Identical Baselines) */}
        <section className="grid grid-cols-2 lg:grid-cols-4 gap-5">
          
          <div className="bg-white border border-slate-200/90 p-6 rounded-2xl shadow-md flex flex-col justify-between hover:shadow-lg transition-all">
            <div>
              <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-1">
                {t("dashMatchingNotices")}
              </span>
              <div className="text-2xl sm:text-3xl font-black text-[#0055B8]">
                {MOCK_TENDERS.length} {t("dashLive")}
              </div>
            </div>
            <div className="pt-3 mt-3 border-t border-slate-100 text-[11px] text-slate-500 font-medium">
              {t("dashInTradeSectors")}
            </div>
          </div>

          <div className="bg-white border border-slate-200/90 p-6 rounded-2xl shadow-md flex flex-col justify-between hover:shadow-lg transition-all">
            <div>
              <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-1">
                {t("dashClosingWeek")}
              </span>
              <div className="text-2xl sm:text-3xl font-black text-[#0F172A]">
                4 {t("dashUrgent")}
              </div>
            </div>
            <div className="pt-3 mt-3 border-t border-slate-100 text-[11px] text-slate-500 font-medium">
              {t("dashDeadline7Days")}
            </div>
          </div>

          <div className="bg-white border border-slate-200/90 p-6 rounded-2xl shadow-md flex flex-col justify-between hover:shadow-lg transition-all">
            <div>
              <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-1">
                {t("dashActiveWatchlist")}
              </span>
              <div className="text-2xl sm:text-3xl font-black text-[#0055B8]">
                {watchlist.length} {t("dashSaved")}
              </div>
            </div>
            <div className="pt-3 mt-3 border-t border-slate-100 text-[11px] text-slate-500 font-medium">
              {t("dashPinnedAlerts")}
            </div>
          </div>

          <div className="bg-white border border-slate-200/90 p-6 rounded-2xl shadow-md flex flex-col justify-between hover:shadow-lg transition-all">
            <div>
              <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-1">
                {t("dashPipelineValue")}
              </span>
              <div className="text-2xl sm:text-3xl font-black text-[#0F172A] font-mono">
                LKR 285.4M
              </div>
            </div>
            <div className="pt-3 mt-3 border-t border-slate-100 text-[11px] text-slate-500 font-medium">
              {t("dashAggregateBand")}
            </div>
          </div>

        </section>

        {/* 3. MAIN WORKSPACE 2-COLUMN STRUCTURE (Clean Linear Box Layout) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
          
          {/* LEFT SIDEBAR: BOX-BY-BOX CONTROL STACK */}
          <aside className="lg:col-span-4 xl:col-span-3 space-y-5 sticky top-28">
            
            {/* Box 1: Authorized Officer Badge Box */}
            <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-md flex items-center gap-4">
              <div className="w-12 h-12 rounded-xl bg-[#0055B8] text-white flex items-center justify-center font-display text-xl font-black shrink-0 shadow-sm">
                KP
              </div>
              <div className="truncate">
                <h2 className="text-sm font-black text-[#0F172A] truncate">
                  {userProfile.name}
                </h2>
                <div className="text-xs text-[#0055B8] font-bold">
                  {t("dashAuthorizedOfficer")}
                </div>
                <div className="text-[11px] text-slate-400 font-mono truncate mt-0.5">
                  {userProfile.email}
                </div>
              </div>
            </div>

            {/* Box 2: Workspace Navigation Box */}
            <nav className="bg-white border border-slate-200/90 rounded-2xl p-3 shadow-md divide-y divide-slate-100">
              {[
                { id: "overview", label: t("dashOverview") },
                { id: "related", label: t("dashRelatedLive") },
                { id: "favorites", label: t("dashFavourite"), badge: watchlist.length },
                { id: "settings", label: t("dashCompanyDetails") },
              ].map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => setActiveView(item.id as DashboardView)}
                  className={`w-full text-left px-4 py-3 rounded-xl text-xs font-black transition-all flex items-center justify-between cursor-pointer ${
                    activeView === item.id
                      ? "bg-[#EFF6FF] text-[#0055B8] shadow-2xs"
                      : "text-slate-700 hover:bg-slate-50 hover:text-slate-950 font-bold"
                  }`}
                >
                  <span>{item.label}</span>
                  <div className="flex items-center gap-1.5">
                    {item.badge !== undefined && (
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#0055B8] text-white">
                        {item.badge}
                      </span>
                    )}
                    <span className="text-slate-400 font-bold">&rsaquo;</span>
                  </div>
                </button>
              ))}

              <button
                type="button"
                onClick={handleLogout}
                className="w-full text-left px-4 py-3 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors flex items-center justify-between cursor-pointer"
              >
                <span>{t("dashLogout")}</span>
                <span className="text-slate-400 font-bold">&rsaquo;</span>
              </button>
            </nav>

            {/* Box 3: Procurement Helpdesk Box */}
            <div className="bg-white border border-slate-200/90 rounded-2xl p-5 text-xs shadow-md">
              <span className="text-[10px] font-black uppercase tracking-widest text-[#0055B8] block mb-1">
                {t("dashHelpdesk")}
              </span>
              <div className="font-black text-slate-900 text-sm mb-1">{t("dashCidaSupport")}</div>
              <p className="text-slate-600 font-normal leading-relaxed mb-3">
                {t("dashNeedGuidance")}
              </p>
              <div className="font-mono text-xs font-bold text-slate-900 bg-[#F8FAFC] p-2.5 rounded-xl border border-slate-200 text-center">
                {t("dashHotline")}
              </div>
            </div>

          </aside>

          {/* RIGHT MAIN CONTENT: BOX-BY-BOX LINEAR FEED */}
          <main className="lg:col-span-8 xl:col-span-9 space-y-6">
            
            {/* VIEW 1: DASHBOARD OVERVIEW */}
            {activeView === "overview" && (
              <div className="space-y-6 animate-fadeIn">
                
                {/* Box 1: Welcome Overview Card */}
                <div className="bg-white border border-slate-200/90 p-7 rounded-2xl shadow-md">
                  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 mb-4 border-b border-slate-100">
                    <div>
                      <span className="text-[10px] font-black uppercase tracking-wider text-[#0055B8] block mb-0.5">
                        {t("dashCentralRepo")}
                      </span>
                      <h2 className="text-xl sm:text-2xl font-black text-[#0F172A] tracking-tight">
                        {t("dashNationalFeed")}
                      </h2>
                    </div>
                    <span className="text-xs font-mono font-bold text-slate-500 bg-[#F8FAFC] px-3 py-1.5 rounded-xl border border-slate-200 shrink-0">
                      {t("dashSyncDaily")}
                    </span>
                  </div>

                  <p className="text-xs sm:text-sm text-slate-600 font-normal leading-relaxed mb-6">
                    {t("dashAllNoticesHarvested")}
                  </p>

                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      type="button"
                      onClick={() => setActiveView("related")}
                      className="px-5 py-2.5 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-md transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider cursor-pointer"
                    >
                      {t("dashBrowseMatching")}
                    </button>
                    <button
                      type="button"
                      onClick={() => setActiveView("favorites")}
                      className="px-5 py-2.5 bg-[#F1F5F9] hover:bg-slate-200 text-slate-800 font-bold text-xs rounded-xl border border-slate-200 transition-all active:scale-95 cursor-pointer uppercase tracking-wider"
                    >
                      {t("dashViewWatchlist")} ({watchlist.length})
                    </button>
                  </div>
                </div>

                {/* Box 2: Priority Tenders Linear Stack */}
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <h3 className="text-base sm:text-lg font-black text-[#0F172A]">
                      {t("dashPriorityTenders")}
                    </h3>
                    <button
                      type="button"
                      onClick={() => setActiveView("related")}
                      className="text-xs font-black text-[#0055B8] hover:underline cursor-pointer"
                    >
                      {t("dashViewAllRelated")}
                    </button>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {MOCK_TENDERS.slice(0, 4).map((tender) => {
                      const isSaved = watchlist.includes(tender.id);
                      return (
                        <div
                          key={tender.id}
                          className="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-md hover:shadow-xl transition-all duration-200 flex flex-col justify-between"
                        >
                          <div>
                            <div className="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-slate-100 text-xs">
                              <span className="text-[#0055B8] font-black uppercase text-[11px] truncate">
                                {tender.entity}
                              </span>
                              <span className="bg-[#EFF6FF] text-[#0055B8] font-bold text-[11px] px-2.5 py-0.5 rounded-lg border border-[#BFDBFE]">
                                {tender.daysLeft}{t("daysLeftText")}
                              </span>
                            </div>

                            <h4 className="text-sm font-black text-[#0F172A] mb-2 leading-snug line-clamp-2">
                              {tender.title}
                            </h4>

                            <div className="flex flex-wrap items-center gap-2 text-xs mb-4">
                              <span className="bg-[#F1F5F9] text-[#0055B8] font-bold px-2.5 py-1 rounded-xl text-[11px] border border-slate-200">
                                {tender.categoryName}
                              </span>
                              <span className="text-slate-400 font-mono text-[11px]">
                                {t("refLabel")} {tender.ref}
                              </span>
                            </div>
                          </div>

                          <div className="pt-3 border-t border-slate-100 flex items-center justify-between">
                            <div>
                              <span className="text-[10px] font-bold uppercase text-slate-400 block">{t("dashEstBudget")}</span>
                              <span className="text-sm font-black text-[#0F172A] font-mono">{tender.amount}</span>
                            </div>

                            <div className="flex items-center gap-2">
                              <button
                                type="button"
                                onClick={() => toggleBookmark(tender.id, tender.ref)}
                                className={`px-3 py-1.5 rounded-xl border transition-all text-xs font-bold cursor-pointer ${
                                  isSaved ? "bg-[#EFF6FF] text-[#0055B8] border-[#BFDBFE]" : "bg-[#F8FAFC] text-slate-400 border-slate-200 hover:text-[#0055B8]"
                                }`}
                              >
                                {isSaved ? t("dashSaved") : t("dashSave")}
                              </button>
                              <Link
                                href={`/tender/${tender.id}`}
                                className="px-3.5 py-1.5 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-xs transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider"
                              >
                                {t("dashDossier")}
                              </Link>
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>

              </div>
            )}

            {/* VIEW 2: RELATED LIVE TENDERS */}
            {activeView === "related" && (
              <div className="space-y-6 animate-fadeIn">
                <div className="bg-white border border-slate-200/90 p-6 sm:p-7 rounded-2xl shadow-md">
                  <h2 className="text-xl font-black text-[#0F172A] mb-2">
                    {t("dashRelatedTitle")}
                  </h2>
                  <p className="text-xs sm:text-sm text-slate-600 font-normal leading-relaxed mb-6">
                    {t("dashRelatedDesc")}
                  </p>

                  {/* Search & Sector Filters */}
                  <div className="grid grid-cols-1 sm:grid-cols-12 gap-4">
                    <div className="sm:col-span-7">
                      <input
                        type="text"
                        placeholder={t("dashSearchRelatedPlaceholder")}
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all placeholder:text-slate-400 placeholder:font-normal"
                      />
                    </div>
                    <div className="sm:col-span-5 flex items-center gap-2 overflow-x-auto pb-1">
                      {[
                        { id: "all", label: t("dashAllSectors") },
                        { id: "construction", label: t("dashCivilWorks") },
                        { id: "solar", label: t("dashSolarEnergy") },
                        { id: "it", label: t("dashITServers") },
                      ].map((sec) => (
                        <button
                          key={sec.id}
                          type="button"
                          onClick={() => setSelectedSector(sec.id)}
                          className={`px-3.5 py-2.5 rounded-xl text-xs font-black transition-all whitespace-nowrap cursor-pointer ${
                            selectedSector === sec.id
                              ? "bg-[#0055B8] text-white shadow-xs"
                              : "bg-[#F1F5F9] text-slate-700 hover:bg-slate-200 border border-slate-200"
                          }`}
                        >
                          {sec.label}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>

                {/* Tender Cards Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                  {relatedTenders.map((tender) => {
                    const isSaved = watchlist.includes(tender.id);
                    return (
                      <div
                        key={tender.id}
                        className="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-md hover:shadow-xl transition-all duration-200 flex flex-col justify-between"
                      >
                        <div>
                          <div className="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-slate-100 text-xs">
                            <span className="text-[#0055B8] font-black uppercase text-[11px] truncate">
                              {tender.entity}
                            </span>
                            <span className="bg-[#EFF6FF] text-[#0055B8] font-bold text-[11px] px-2.5 py-0.5 rounded-lg border border-[#BFDBFE]">
                              {tender.daysLeft}{t("daysLeftText")}
                            </span>
                          </div>

                          <h3 className="text-base font-black text-[#0F172A] mb-2 leading-snug">
                            {tender.title}
                          </h3>

                          <div className="flex flex-wrap items-center gap-2 text-xs mb-4">
                            <span className="bg-[#F1F5F9] text-[#0055B8] font-bold px-3 py-1 rounded-xl text-xs border border-slate-200">
                              {tender.categoryName}
                            </span>
                            <span className="bg-[#F1F5F9] text-slate-700 font-semibold px-3 py-1 rounded-xl text-xs border border-slate-200">
                              {tender.district}
                            </span>
                            <span className="text-slate-400 font-mono text-[11px]">
                              {tender.ref}
                            </span>
                          </div>
                        </div>

                        <div className="pt-4 border-t border-slate-100 flex items-center justify-between">
                          <div>
                            <span className="text-[10px] font-bold uppercase text-slate-400 block">{t("dashEstBudget")}</span>
                            <span className="text-base font-black text-[#0F172A] font-mono">{tender.amount}</span>
                          </div>

                          <div className="flex items-center gap-2">
                            <button
                              type="button"
                              onClick={() => toggleBookmark(tender.id, tender.ref)}
                              className={`p-2.5 rounded-xl border text-xs font-black transition-all cursor-pointer ${
                                isSaved ? "bg-[#EFF6FF] text-[#0055B8] border-[#BFDBFE]" : "bg-[#F8FAFC] text-slate-600 border-slate-200 hover:text-[#0055B8]"
                              }`}
                            >
                              {isSaved ? t("dashSaved") : t("dashSave")}
                            </button>
                            <Link
                              href={`/tender/${tender.id}`}
                              className="px-4 py-2.5 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-xs transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider"
                            >
                              {t("dashViewDossier")}
                            </Link>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* VIEW 3: FAVOURITES / WATCHLIST */}
            {activeView === "favorites" && (
              <div className="space-y-6 animate-fadeIn">
                <div className="bg-white border border-slate-200/90 p-6 sm:p-7 rounded-2xl shadow-md flex items-center justify-between">
                  <div>
                    <h2 className="text-xl font-black text-[#0F172A]">
                      {t("dashWatchlistTitle")} ({watchlist.length})
                    </h2>
                    <p className="text-xs sm:text-sm text-slate-600 font-normal mt-0.5">
                      {t("dashMonitoredTenders")}
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => setActiveView("related")}
                    className="px-4 py-2 bg-[#EFF6FF] text-[#0055B8] border border-[#BFDBFE] font-black text-xs rounded-xl hover:bg-blue-100 transition-colors cursor-pointer"
                  >
                    {t("dashAddMore")}
                  </button>
                </div>

                {favoriteTenders.length === 0 ? (
                  <div className="bg-white border border-slate-200/90 rounded-2xl p-12 text-center shadow-md">
                    <div className="text-slate-400 font-bold text-lg mb-2">{t("dashEmptyWatchlist")}</div>
                    <p className="text-xs sm:text-sm text-slate-500 font-normal max-w-md mx-auto mb-6">
                      {t("dashClickSave")}
                    </p>
                    <button
                      type="button"
                      onClick={() => setActiveView("related")}
                      className="px-6 py-3 bg-[#0055B8] text-white font-black text-xs rounded-xl uppercase tracking-wider shadow-md cursor-pointer"
                    >
                      {t("dashBrowseAvailable")}
                    </button>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {favoriteTenders.map((tender) => (
                      <div
                        key={tender.id}
                        className="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-md hover:shadow-lg transition-all flex flex-col md:flex-row md:items-center justify-between gap-6"
                      >
                        <div className="space-y-1.5 flex-1">
                          <div className="flex items-center gap-2">
                            <span className="text-[#0055B8] font-black text-xs uppercase">{tender.entity}</span>
                            <span className="text-slate-300">&bull;</span>
                            <span className="font-mono text-xs text-slate-500">{tender.ref}</span>
                          </div>
                          <h3 className="text-base font-black text-[#0F172A] leading-snug">
                            {tender.title}
                          </h3>
                          <div className="flex flex-wrap items-center gap-3 text-xs text-slate-600 font-normal">
                            <span>{t("dashDeadline")} <strong className="font-bold text-[#0055B8]">{tender.endDate} ({tender.daysLeft}{t("daysLeftText")})</strong></span>
                            <span>&bull;</span>
                            <span>{t("dashBidBond")} <strong className="font-bold text-slate-900">{tender.bidBond}</strong></span>
                            <span>&bull;</span>
                            <span>{t("dashFee")} <strong className="font-bold text-slate-900">{tender.docFee}</strong></span>
                          </div>
                        </div>

                        <div className="flex items-center gap-3 shrink-0">
                          <button
                            type="button"
                            onClick={() => toggleBookmark(tender.id, tender.ref)}
                            className="px-4 py-2.5 text-xs font-bold text-slate-600 hover:text-red-700 bg-[#F8FAFC] border border-slate-200 rounded-xl transition-colors cursor-pointer"
                          >
                            {t("dashRemove")}
                          </button>
                          <Link
                            href={`/tender/${tender.id}`}
                            className="px-5 py-2.5 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-md transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider"
                          >
                            {t("dashFullDossier")}
                          </Link>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* VIEW 4: COMPANY & USER DETAILS / SETTINGS */}
            {activeView === "settings" && (
              <div className="bg-white border border-slate-200/90 rounded-2xl p-7 sm:p-10 shadow-md animate-fadeIn">
                <h2 className="text-xl sm:text-2xl font-black text-[#0F172A] mb-2">
                  {t("dashCompanyProfile")}
                </h2>
                <p className="text-xs sm:text-sm text-slate-600 font-normal leading-relaxed mb-8">
                  {t("dashKeepAuthorized")}
                </p>

                <form onSubmit={handleSaveSettings} className="space-y-6">
                  
                  {/* Company Name & Registration */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div className="flex flex-col gap-1.5">
                      <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                        {t("dashRegisteredBusinessName")}
                      </label>
                      <input
                        type="text"
                        required
                        value={userProfile.company}
                        onChange={(e) => setUserProfile({ ...userProfile, company: e.target.value })}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all"
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                        {t("dashBRN")}
                      </label>
                      <input
                        type="text"
                        required
                        value={userProfile.brn}
                        onChange={(e) => setUserProfile({ ...userProfile, brn: e.target.value })}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-mono font-bold text-slate-900 outline-none transition-all"
                      />
                    </div>
                  </div>

                  {/* Authorized Officer & CIDA Grade */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div className="flex flex-col gap-1.5">
                      <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                        {t("dashAuthorizedOfficerLabel")}
                      </label>
                      <input
                        type="text"
                        required
                        value={userProfile.name}
                        onChange={(e) => setUserProfile({ ...userProfile, name: e.target.value })}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all"
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                        {t("dashCidaGradeLabel")}
                      </label>
                      <input
                        type="text"
                        required
                        value={userProfile.cidaGrade}
                        onChange={(e) => setUserProfile({ ...userProfile, cidaGrade: e.target.value })}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all"
                      />
                    </div>
                  </div>

                  {/* Email & Phone */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div className="flex flex-col gap-1.5">
                      <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                        {t("dashCorporateEmailLabel")}
                      </label>
                      <input
                        type="email"
                        required
                        value={userProfile.email}
                        onChange={(e) => setUserProfile({ ...userProfile, email: e.target.value })}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all"
                      />
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                        {t("dashMobileWhatsApp")}
                      </label>
                      <input
                        type="tel"
                        required
                        value={userProfile.phone}
                        onChange={(e) => setUserProfile({ ...userProfile, phone: e.target.value })}
                        className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-mono font-bold text-slate-900 outline-none transition-all"
                      />
                    </div>
                  </div>

                  {/* Alert Delivery Settings */}
                  <div className="p-5 bg-[#F8FAFC] rounded-2xl border border-slate-200 space-y-3">
                    <div className="text-xs font-black uppercase tracking-wider text-[#0055B8]">
                      {t("dashAlertPrefs")}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-4 text-xs">
                      <div>
                        <strong className="text-slate-900 font-bold block">{t("dashWhatsAppInstant")}</strong>
                        <span className="text-slate-500 font-normal">{t("dashReceiveImmediate")}</span>
                      </div>
                      <button
                        type="button"
                        onClick={() => setUserProfile({ ...userProfile, whatsappAlerts: !userProfile.whatsappAlerts })}
                        className={`px-4 py-1.5 rounded-xl font-bold text-xs transition-colors cursor-pointer ${
                          userProfile.whatsappAlerts ? "bg-[#0055B8] text-white" : "bg-slate-200 text-slate-700"
                        }`}
                      >
                        {userProfile.whatsappAlerts ? t("dashActive") : t("dashDisabled")}
                      </button>
                    </div>
                  </div>

                  <div className="pt-4 border-t border-slate-100 flex items-center justify-between">
                    <button
                      type="submit"
                      className="px-8 py-3.5 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-md transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider cursor-pointer"
                    >
                      {t("dashSaveChanges")}
                    </button>
                    <span className="text-xs text-slate-400 font-normal">
                      {t("dashLastSync")}
                    </span>
                  </div>
                </form>
              </div>
            )}

          </main>
        </div>
      </div>
    </div>
  );
}

export default function SupplierDashboardPage() {
  return (
    <Suspense
      fallback={
        <div className="min-h-screen bg-[#F8FAFC] flex items-center justify-center p-6">
          <div className="text-center">
            <div className="w-8 h-8 border-3 border-[#0055B8] border-t-transparent rounded-full animate-spin mx-auto mb-3" />
            <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">
              Loading Supplier Workspace...
            </span>
          </div>
        </div>
      }
    >
      <SupplierDashboardContent />
    </Suspense>
  );
}
