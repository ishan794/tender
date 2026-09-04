"use client";
import { useState, useMemo, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import Link from "next/link";
import { useRouter } from "next/navigation";
import type { TenderItem } from "@/data/tenders";
import { useToast } from "@/components/ui/Toaster";
import { useLanguage } from "@/context/LanguageContext";

const CATEGORIES = [
  { id: "construction", name: "Construction" },
  { id: "it", name: "Computer & IT" },
  { id: "suppliers", name: "Registration of Suppliers" },
  { id: "unclassified", name: "Unclassified" },
  { id: "electrical", name: "Electrical" },
  { id: "electronics", name: "Electronics" },
  { id: "medical", name: "Medical & Pharmaceuticals" },
  { id: "cleaning", name: "Cleaning & Janitorial Services" },
  { id: "security", name: "Manpower & Security Services" },
  { id: "hardware", name: "Hardware" },
  { id: "vehicles", name: "Vehicles, Auto Parts & Services" },
  { id: "printing", name: "Printing & Advertising" },
  { id: "agriculture", name: "Agriculture" },
  { id: "transport", name: "Transport & Rent A Car Services" },
  { id: "consultancy", name: "Consultancy, Audit & Tax Services" },
  { id: "furniture", name: "Furniture" },
  { id: "services", name: "Services" },
  { id: "laboratory", name: "Laboratory & Chemicals" },
  { id: "finance", name: "Bank, Finance & Insurance" },
  { id: "stationery", name: "Gift & Stationery" },
  { id: "fashion", name: "Fashion & Textiles" },
  { id: "food", name: "Food & Beverage" },
  { id: "courier", name: "Courier & Logistics" },
  { id: "plastic", name: "Plastic & Rubber" },
  { id: "solar", name: "Renewable Energy & Solar" },
];

const SECTORS = [
  { id: "all", name: "All Procurement Sectors" },
  { id: "government", name: "Government Tenders" },
  { id: "private", name: "Private Tenders" },
];

const PROVINCES = [
  { id: "all", name: "All Provinces (National)" },
  { id: "western", name: "Western Province" },
  { id: "central", name: "Central Province" },
  { id: "southern", name: "Southern Province" },
  { id: "eastern", name: "Eastern Province" },
  { id: "northern", name: "Northern Province" },
  { id: "north-western", name: "North Western Province" },
  { id: "north-central", name: "North Central Province" },
  { id: "sabaragamuwa", name: "Sabaragamuwa Province" },
  { id: "uva", name: "Uva Province" },
];

const VALUE_BANDS = [
  { id: "all", name: "All Value Bands" },
  { id: "<5M", name: "Under Rs. 5M (Micro/SME)" },
  { id: "5M-25M", name: "Rs. 5M - 25M (Standard)" },
  { id: "25M-100M", name: "Rs. 25M - 100M (Corporate)" },
  { id: "100M-500M", name: "Rs. 100M - 500M (Major Works)" },
  { id: ">500M", name: "Over Rs. 500M (Mega Projects)" },
];

export interface HomeClientProps {
  initialNotices?: TenderItem[];
  initialStats?: {
    live: number;
    archived: number;
    auctions: number;
    added_today: number;
    authorities: number;
    awards: number;
  };
  initialFacets?: any;
  initialStatusCounts?: {
    all?: number;
    live?: number;
    closing_soon?: number;
    closed?: number;
  };
}

export function HomeClient({
  initialNotices = [],
  initialStats = { live: 0, archived: 0, auctions: 0, added_today: 0, authorities: 0, awards: 0 },
  initialFacets = {},
  initialStatusCounts = {},
}: HomeClientProps) {
  const router = useRouter();
  const toast = useToast();
  const { t, language } = useLanguage();

  const [keyword, setKeyword] = useState("");
  const [isSearchFocused, setIsSearchFocused] = useState(false);
  const [selectedCategory, setSelectedCategory] = useState<string>("all");
  const [selectedProvince, setSelectedProvince] = useState<string>("all");
  const [selectedValueBand, setSelectedValueBand] = useState<string>("all");
  const [closingWindow, setClosingWindow] = useState<string>("all");
  const [sectorFilter, setSectorFilter] = useState<string>("all");
  const [statusTab, setStatusTab] = useState<"all" | "today" | "live" | "closing" | "closed" | "suppliers">("live");
  const [sortBy, setSortBy] = useState("closing");
  const [activePreset, setActivePreset] = useState<string | null>(null);

  // View Mode: Cards vs Dense List
  const [viewMode, setViewMode] = useState<"cards" | "list">("cards");
  const [isMobileFiltersExpanded, setIsMobileFiltersExpanded] = useState(false);
  const [isMobileSideMenuOpen, setIsMobileSideMenuOpen] = useState(false);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  // Lock body scroll when mobile side menu is open
  useEffect(() => {
    if (isMobileSideMenuOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isMobileSideMenuOpen]);

  // Bookmarking Watchlist
  const [savedTenders, setSavedTenders] = useState<Set<string>>(new Set());
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  useEffect(() => {
    setIsLoggedIn(document.cookie.includes("tenderhub_auth"));
  }, []);

  // Modern Dropdown State
  const [activeDropdown, setActiveDropdown] = useState<string | null>(null);

  // Translation Helper Functions for Dynamic Trilingual Support
  const getCategoryName = (id: string, fallback?: string) => {
    const map: Record<string, string> = {
      "construction": "catCivil",
      "it": "catIT",
      "suppliers": "catSuppliers",
      "unclassified": "catUnclassified",
      "electrical": "catElectrical",
      "electronics": "catTelecom",
      "medical": "catMedical",
      "cleaning": "catJanitorial",
      "security": "catSecurity",
      "hardware": "catMachinery",
      "vehicles": "catVehicles",
      "printing": "catPrinting",
      "agriculture": "catAgriculture",
      "transport": "catTransport",
      "consultancy": "catConsultancy",
      "furniture": "catFurniture",
      "services": "catServices",
      "laboratory": "catLaboratory",
      "finance": "catBankFinance",
      "stationery": "catGift",
      "fashion": "catFashion",
      "food": "catFood",
      "courier": "catCourier",
      "plastic": "catPlastic",
      "solar": "catSolar",
    };
    return map[id] ? t(map[id]) : (fallback || id);
  };

  const getProvinceName = (id: string, fallback?: string) => {
    const map: Record<string, string> = {
      "western": "provWestern",
      "central": "provCentral",
      "southern": "provSouthern",
      "northern": "provNorthern",
      "eastern": "provEastern",
      "north-western": "provNorthWestern",
      "north-central": "provNorthCentral",
      "uva": "provUva",
      "sabaragamuwa": "provSabaragamuwa",
    };
    return map[id] ? t(map[id]) : (fallback || id);
  };

  const getValueBandName = (id: string, fallback?: string) => {
    const map: Record<string, string> = {
      "<5M": "bandUnder5M",
      "5M-25M": "band5M25M",
      "25M-100M": "band25M100M",
      "100M-500M": "band100M500M",
      ">500M": "bandOver500M",
    };
    return map[id] ? t(map[id]) : (fallback || id);
  };

  const getSectorName = (id: string, fallback?: string) => {
    const map: Record<string, string> = {
      "all": "allTendersLabel",
      "government": "govTenders",
      "semi-government": "secSemiGov",
      "private": "privateTenders",
    };
    return map[id] ? t(map[id]) : (fallback || id);
  };

  const getDeadlineName = (id: string) => {
    if (id === "3days") return t("urgent3DaysOpt");
    if (id === "7days") return t("next7DaysOpt");
    if (id === "30days") return t("next30DaysOpt");
    return t("anyClosingDateOpt");
  };

  // Dynamic Typing Search Animation (Fully Localized for EN / SI / TA)
  const TYPING_SUGGESTIONS = useMemo(() => {
    if (language === "si") {
      return [
        t("searchPlaceholder"),
        "සොයන්න 'සිවිල් ඉදිකිරීම් හා මාර්ග සංවර්ධන'...",
        "සොයන්න 'සූර්ය බලශක්ති හා පුනර්ජනනීය ව්‍යාපෘති'...",
        "සොයන්න 'පරිගණක හා තොරතුරු තාක්ෂණ සේවා'...",
        "සොයන්න 'රෝහල් ඖෂධ හා ශල්‍ය ද්‍රව්‍ය'...",
        "සොයන්න 'සැපයුම්කරුවන් ලියාපදිංචිය 2026'...",
      ];
    }
    if (language === "ta") {
      return [
        t("searchPlaceholder"),
        "தேடுங்கள் 'கட்டிட நிர்மாணம் மற்றும் பணிகள்'...",
        "தேடுங்கள் 'சூரிய சக்தி மற்றும் புதுப்பிக்கத்தக்க சக்தி'...",
        "தேடுங்கள் 'கணினி மற்றும் IT சேவைகள்'...",
        "தேடுங்கள் 'மருத்துவம் மற்றும் மருந்துகள்'...",
        "தேடுங்கள் 'வழங்குநர் பதிவு 2026'...",
      ];
    }
    return [
      t("searchPlaceholder"),
      "Try 'Civil Construction & Road Infrastructure'...",
      "Try 'Solar Power & Renewable Energy Parks'...",
      "Try 'Enterprise Server IT & Hardware'...",
      "Try 'Medical & Hospital Pharmaceutical Supplies'...",
      "Try 'Registration of Verified Suppliers 2026'...",
    ];
  }, [language, t]);

  const [typingIndex, setTypingIndex] = useState(0);
  const [displayedPlaceholder, setDisplayedPlaceholder] = useState("");
  const [isDeleting, setIsDeleting] = useState(false);

  useEffect(() => {
    const currentText = TYPING_SUGGESTIONS[typingIndex] || t("searchPlaceholder");
    let timer: NodeJS.Timeout;

    if (!isDeleting && displayedPlaceholder === currentText) {
      timer = setTimeout(() => setIsDeleting(true), 2400);
    } else if (isDeleting && displayedPlaceholder === "") {
      setIsDeleting(false);
      setTypingIndex((prev) => (prev + 1) % TYPING_SUGGESTIONS.length);
    } else {
      const speed = isDeleting ? 20 : 40;
      timer = setTimeout(() => {
        setDisplayedPlaceholder(
          currentText.substring(
            0,
            displayedPlaceholder.length + (isDeleting ? -1 : 1)
          )
        );
      }, speed);
    }

    return () => clearTimeout(timer);
  }, [displayedPlaceholder, isDeleting, typingIndex, TYPING_SUGGESTIONS, t]);

  const searchInputRef = useRef<HTMLInputElement>(null);
  const searchContainerRef = useRef<HTMLDivElement>(null);
  const filterBarRef = useRef<HTMLDivElement>(null);
  const sortDropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (searchContainerRef.current && !searchContainerRef.current.contains(e.target as Node)) {
        setIsSearchFocused(false);
      }
      const inFilter = filterBarRef.current && filterBarRef.current.contains(e.target as Node);
      const inSort = sortDropdownRef.current && sortDropdownRef.current.contains(e.target as Node);
      if (!inFilter && !inSort) {
        setActiveDropdown(null);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "/" && document.activeElement !== searchInputRef.current) {
        e.preventDefault();
        searchInputRef.current?.focus();
      } else if (e.key === "Escape") {
        if (isSearchFocused) setIsSearchFocused(false);
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isSearchFocused]);

  const toggleBookmark = (e: React.MouseEvent, id: string) => {
    e.preventDefault();
    e.stopPropagation();
    const isSaved = savedTenders.has(id);
    setSavedTenders((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
        toast.info("Watchlist Updated", "Notice removed from your saved watchlist.");
      } else {
        next.add(id);
        toast.success("Saved to Watchlist", "Notice added to your workspace watchlist.");
      }
      return next;
    });
  };

  const handleStatusTabChange = (tab: "all" | "today" | "live" | "closing" | "closed" | "suppliers") => {
    setStatusTab(tab);
    if (tab === "suppliers") {
      setSelectedCategory("suppliers");
      setClosingWindow("all");
    } else if (tab === "closing") {
      setClosingWindow("7days");
      setSelectedCategory("all");
    } else if (tab === "today") {
      setClosingWindow("all");
      setSelectedCategory("all");
    } else if (tab === "closed") {
      setClosingWindow("all");
      setSelectedCategory("all");
    } else {
      setClosingWindow("all");
      setSelectedCategory("all");
    }
  };


  // Dynamic live count calculations from real notices & stats
  const totalCount = initialNotices.length;
  
  const categoryCounts = useMemo(() => {
    const map: Record<string, number> = {};
    for (const item of initialNotices) {
      if (item.categoryId) {
        map[item.categoryId] = (map[item.categoryId] || 0) + 1;
      }
    }
    return map;
  }, [initialNotices]);

  const sectorCounts = useMemo(() => {
    const map: Record<string, number> = {};
    for (const item of initialNotices) {
      if (item.sector) {
        map[item.sector] = (map[item.sector] || 0) + 1;
      }
    }
    return map;
  }, [initialNotices]);

  const provinceCounts = useMemo(() => {
    const map: Record<string, number> = {};
    for (const item of initialNotices) {
      if (item.province) {
        map[item.province] = (map[item.province] || 0) + 1;
      }
    }
    return map;
  }, [initialNotices]);

  const liveCount = initialStats.live || initialNotices.filter((n) => n.daysLeft > 0).length;
  const closedCount = initialStats.archived || initialNotices.filter((n) => n.daysLeft <= 0).length;
  const closingSoonCount = initialStatusCounts?.closing_soon ?? initialNotices.filter((n) => n.daysLeft <= 7 && n.daysLeft > 0).length;
  const todayCount = initialStats.added_today || 0;
  const supplierCount = categoryCounts["suppliers"] || initialNotices.filter((n) => n.categoryId === "suppliers" || n.categoryName?.toLowerCase().includes("supplier")).length;

  const filteredTenders = useMemo(() => {
    let result = initialNotices.filter((item) => {
      if (statusTab === "live" && item.daysLeft <= 0) return false;
      if (statusTab === "closed" && item.daysLeft > 0) return false;
      if (statusTab === "closing" && (item.daysLeft > 7 || item.daysLeft <= 0)) return false;
      if (statusTab === "suppliers" && item.categoryId !== "suppliers") return false;
      const matchKeyword =
        keyword === "" ||
        item.title.toLowerCase().includes(keyword.toLowerCase()) ||
        item.entity.toLowerCase().includes(keyword.toLowerCase()) ||
        item.ref.toLowerCase().includes(keyword.toLowerCase()) ||
        item.categoryName.toLowerCase().includes(keyword.toLowerCase()) ||
        item.source.toLowerCase().includes(keyword.toLowerCase());

      const matchCategory =
        selectedCategory === "all" || item.categoryId === selectedCategory;

      const matchProvince =
        selectedProvince === "all" || item.province === selectedProvince;

      const matchValueBand =
        selectedValueBand === "all" || item.valueBand === selectedValueBand;

      const matchSector =
        sectorFilter === "all" || item.sector === sectorFilter;

      const matchClosing =
        closingWindow === "all" ||
        (closingWindow === "3days" && item.daysLeft <= 3) ||
        (closingWindow === "7days" && item.daysLeft <= 7) ||
        (closingWindow === "30days" && item.daysLeft <= 30);

      const matchHighValue =
        activePreset !== "highValue" || item.amountNumeric >= 30000000;

      return matchKeyword && matchCategory && matchProvince && matchValueBand && matchSector && matchClosing && matchHighValue;
    });

    if (sortBy === "closing") {
      result.sort((a, b) => a.daysLeft - b.daysLeft);
    } else if (sortBy === "newest") {
      result.sort((a, b) => b.id.localeCompare(a.id));
    } else if (sortBy === "amountDesc") {
      result.sort((a, b) => b.amountNumeric - a.amountNumeric);
    } else if (sortBy === "amountAsc") {
      result.sort((a, b) => a.amountNumeric - b.amountNumeric);
    }

    return result;
  }, [initialNotices, keyword, selectedCategory, selectedProvince, selectedValueBand, sectorFilter, closingWindow, sortBy, activePreset, statusTab]);

  // Pagination State - default 10 to match TenderNotices.lk (Showing 1-10 of 39942)
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(10);

  useEffect(() => {
    setCurrentPage(1);
  }, [keyword, selectedCategory, selectedProvince, selectedValueBand, sectorFilter, closingWindow, sortBy, statusTab]);

  const totalPages = Math.max(1, Math.ceil(filteredTenders.length / itemsPerPage));
  const paginatedTenders = useMemo(() => {
    const start = (currentPage - 1) * itemsPerPage;
    return filteredTenders.slice(start, start + itemsPerPage);
  }, [filteredTenders, currentPage, itemsPerPage]);

  const startIndex = filteredTenders.length === 0 ? 0 : (currentPage - 1) * itemsPerPage + 1;
  const endIndex = Math.min(currentPage * itemsPerPage, filteredTenders.length);

  const handlePageChange = (page: number) => {
    setCurrentPage(page);
    document.getElementById("tender-results-section")?.scrollIntoView({ behavior: "smooth" });
  };

  const handleReset = () => {
    setKeyword("");
    setSelectedCategory("all");
    setSelectedProvince("all");
    setSelectedValueBand("all");
    setClosingWindow("all");
    setSectorFilter("all");
    setStatusTab("live");
    setActivePreset(null);
    setCurrentPage(1);
  };

  const hasActiveFilters =
    keyword !== "" ||
    selectedCategory !== "all" ||
    selectedProvince !== "all" ||
    selectedValueBand !== "all" ||
    sectorFilter !== "all" ||
    closingWindow !== "all" ||
    activePreset !== null;

  return (
    <div className="max-w-[1680px] 2xl:max-w-[1760px] mx-auto px-3 xs:px-4 sm:px-6 lg:px-8 2xl:px-10 py-4 xs:py-6 sm:py-8">
      
      {/* 1. HERO BANNER WITH DYNAMIC METRICS OVERLAY & INTEGRATED SEARCH ENGINE - Fluid responsive */}
      <section className="relative rounded-2xl xs:rounded-3xl sm:rounded-[32px] lg:rounded-[36px] mb-8 xs:mb-12 sm:mb-16 lg:mb-20 shadow-xl sm:shadow-2xl bg-[#0A1633] text-white border border-slate-800 z-30 overflow-hidden">
        
        {/* Full Section Background Image Container (Strictly Rounded & Clipped) */}
        <div className="absolute inset-0 pointer-events-none rounded-2xl xs:rounded-3xl sm:rounded-[32px] lg:rounded-[36px] overflow-hidden">
          <div 
            className="absolute inset-0 bg-cover bg-center opacity-60 xs:opacity-70 sm:opacity-75 scale-105 transition-transform duration-1000"
            style={{
              backgroundImage: `url('https://images.unsplash.com/photo-1588668214407-6ea9a6d8c272?q=80&w=2000&auto=format&fit=crop')`,
            }}
          />
          {/* Soft Contrast Gradient Overlay - stronger on mobile for readability */}
          <div className="absolute inset-0 bg-linear-to-r from-[#07132F]/90 via-[#0A1E4A]/70 to-[#07132F]/85 sm:from-[#07132F]/85 sm:via-[#0A1E4A]/65 sm:to-[#07132F]/80" />
        </div>

        <div className="relative z-10 px-4 xs:px-5 sm:px-8 md:px-12 lg:px-16 xl:px-20 py-10 xs:py-12 sm:py-16 md:py-20 lg:py-28 xl:py-32">
          
          {/* Top Hero Header (Grand Scale) - Responsive typography 320px -> 1920px+ */}
          <div className="max-w-5xl mb-6 xs:mb-8 sm:mb-10 lg:mb-12">
            
            {/* Top Verification Beacon - wraps on tiny screens */}
            <div className="mb-3 xs:mb-4 animate-hero-badge">
              <div className="inline-flex items-center gap-1.5 xs:gap-2 bg-white/10 backdrop-blur-md px-2.5 xs:px-3.5 py-1.5 rounded-full border border-white/20 max-w-full">
                <span className="relative flex h-2 w-2 xs:h-2.5 xs:w-2.5 shrink-0">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 xs:h-2.5 xs:w-2.5 bg-emerald-400"></span>
                </span>
                <span className="text-[9px] xs:text-[10px] sm:text-[11px] font-black uppercase tracking-widest text-blue-200 leading-tight">
                  {t("heroSubtitle")}
                </span>
              </div>
            </div>

            <h1 className="font-display text-2xl xs:text-3xl sm:text-4xl md:text-5xl lg:text-6xl xl:text-7xl 2xl:text-[4.5rem] font-black tracking-tight uppercase leading-[0.95] xs:leading-[1.02] mb-3 xs:mb-4 sm:mb-5 animate-hero-title break-words">
              {t("heroTitle")}
            </h1>

            <p className="text-sm xs:text-[15px] sm:text-base lg:text-lg text-blue-100 font-normal leading-relaxed max-w-3xl animate-hero-desc line-clamp-4 xs:line-clamp-none">
              {t("heroDesc")}
            </p>
          </div>

          {/* FULL INTEGRATED SEARCH & FILTER PANEL INSIDE HERO - Responsive padding */}
          <div className="bg-white rounded-2xl xs:rounded-3xl p-4 xs:p-5 sm:p-6 md:p-8 lg:p-10 xl:p-12 shadow-xl sm:shadow-2xl text-slate-900 border border-slate-100 relative z-30 mx-[-4px] xs:mx-0">
            
            {/* Primary Search Bar */}
            <div className="mb-6 relative" ref={searchContainerRef}>
              <div className="relative">
                <input
                  ref={searchInputRef}
                  type="text"
                  aria-label={t("searchPlaceholder")}
                  placeholder={language === "en" ? (displayedPlaceholder || t("searchPlaceholder")) : t("searchPlaceholder")}
                  value={keyword}
                  onFocus={() => setIsSearchFocused(true)}
                  onChange={(e) => setKeyword(e.target.value)}
                  className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl xs:rounded-2xl py-3.5 xs:py-4 sm:py-4 lg:py-5 pl-4 xs:pl-5 sm:pl-6 lg:pl-7 pr-12 xs:pr-14 sm:pr-16 text-sm xs:text-[15px] sm:text-base lg:text-lg font-semibold text-slate-900 outline-none transition-all placeholder:text-slate-400 placeholder:font-normal placeholder:text-xs xs:placeholder:text-sm sm:placeholder:text-base shadow-2xs min-h-[44px]"
                />
                
                <div className="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-1.5">
                  {keyword && (
                    <button
                      onClick={() => setKeyword("")}
                      className="text-slate-400 hover:text-slate-700 font-black text-lg mr-1 cursor-pointer"
                    >
                      &times;
                    </button>
                  )}
                  <kbd className="hidden sm:inline-block bg-slate-200 text-slate-600 text-[10px] font-mono px-1.5 py-0.5 rounded border border-slate-300">
                    /
                  </kbd>
                </div>
              </div>

              {/* On-Focus Popover */}
              {isSearchFocused && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl z-30 p-3.5 animate-fadeIn">
                  <div className="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 mb-2">
                    {t("recentSearchTitle")}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {["solar infrastructure", "road rehabilitation", "pharmaceuticals", "enterprise server hardware", "janitorial maintenance"].map((term) => (
                      <button
                        key={term}
                        type="button"
                        onClick={() => {
                          setKeyword(term);
                          setIsSearchFocused(false);
                        }}
                        className="bg-slate-100 hover:bg-blue-50 hover:text-[#0055B8] text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors text-slate-700 cursor-pointer"
                      >
                        {term}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* 4 Core Dropdowns + Action CTA - Responsive: 1 col xs, 2 col sm, 3 col lg, 5 col xl */}
            <div ref={filterBarRef} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3 xs:gap-3.5 sm:gap-4 mb-2">
              
              {/* Modern Category Dropdown */}
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setActiveDropdown(activeDropdown === "category" ? null : "category")}
                  className="w-full bg-[#F8FAFC] hover:bg-white border border-slate-200/90 hover:border-[#0055B8] focus:border-[#0055B8] focus:bg-white rounded-2xl p-3 sm:p-3.5 text-left transition-all hover:shadow-sm flex items-center justify-between gap-2 cursor-pointer"
                >
                  <div className="truncate">
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-0.5">
                      {t("categoryLabel")}
                    </span>
                    <span className="text-xs sm:text-sm font-black text-[#0F172A] truncate block">
                      {selectedCategory === "all" ? `${t("allCategoriesOpt")} (${CATEGORIES.length})` : getCategoryName(selectedCategory, CATEGORIES.find(c => c.id === selectedCategory)?.name)}
                    </span>
                  </div>
                  <svg className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200 ${activeDropdown === "category" ? "rotate-180 text-[#0055B8]" : ""}`} viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                  </svg>
                </button>

                {activeDropdown === "category" && (
                  <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-2xl z-50 p-2 max-h-64 overflow-y-auto custom-scrollbar animate-fadeIn divide-y divide-slate-50">
                    <button
                      type="button"
                      onClick={() => { setSelectedCategory("all"); setActiveDropdown(null); }}
                      className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                        selectedCategory === "all" ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                      }`}
                    >
                      <span>{t("allCategoriesOpt")} ({CATEGORIES.length})</span>
                      {selectedCategory === "all" && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                    </button>
                    {CATEGORIES.map((cat) => (
                      <button
                        key={cat.id}
                        type="button"
                        onClick={() => { setSelectedCategory(cat.id); setActiveDropdown(null); }}
                        className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                          selectedCategory === cat.id ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                        }`}
                      >
                        <span className="truncate">{getCategoryName(cat.id, cat.name)}</span>
                        {selectedCategory === cat.id && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Modern Province Dropdown */}
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setActiveDropdown(activeDropdown === "province" ? null : "province")}
                  className="w-full bg-[#F8FAFC] hover:bg-white border border-slate-200/90 hover:border-[#0055B8] focus:border-[#0055B8] focus:bg-white rounded-2xl p-3 sm:p-3.5 text-left transition-all hover:shadow-sm flex items-center justify-between gap-2 cursor-pointer"
                >
                  <div className="truncate">
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-0.5">
                      {t("provinceLabel")}
                    </span>
                    <span className="text-xs sm:text-sm font-black text-[#0F172A] truncate block">
                      {selectedProvince === "all" ? t("allProvincesOpt") : getProvinceName(selectedProvince, PROVINCES.find(p => p.id === selectedProvince)?.name)}
                    </span>
                  </div>
                  <svg className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200 ${activeDropdown === "province" ? "rotate-180 text-[#0055B8]" : ""}`} viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                  </svg>
                </button>

                {activeDropdown === "province" && (
                  <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-2xl z-50 p-2 max-h-64 overflow-y-auto custom-scrollbar animate-fadeIn divide-y divide-slate-50">
                    <button
                      type="button"
                      onClick={() => { setSelectedProvince("all"); setActiveDropdown(null); }}
                      className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                        selectedProvince === "all" ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                      }`}
                    >
                      <span>{t("allProvincesOpt")}</span>
                      {selectedProvince === "all" && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                    </button>
                    {PROVINCES.filter(p => p.id !== "all").map((prov) => (
                      <button
                        key={prov.id}
                        type="button"
                        onClick={() => { setSelectedProvince(prov.id); setActiveDropdown(null); }}
                        className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                          selectedProvince === prov.id ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                        }`}
                      >
                        <span className="truncate">{getProvinceName(prov.id, prov.name)}</span>
                        {selectedProvince === prov.id && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Modern Value Band Dropdown */}
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setActiveDropdown(activeDropdown === "valueBand" ? null : "valueBand")}
                  className="w-full bg-[#F8FAFC] hover:bg-white border border-slate-200/90 hover:border-[#0055B8] focus:border-[#0055B8] focus:bg-white rounded-2xl p-3 sm:p-3.5 text-left transition-all hover:shadow-sm flex items-center justify-between gap-2 cursor-pointer"
                >
                  <div className="truncate">
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-0.5">
                      {t("valueBandLabel")}
                    </span>
                    <span className="text-xs sm:text-sm font-black text-[#0F172A] truncate block">
                      {selectedValueBand === "all" ? t("allValueBandsOpt") : getValueBandName(selectedValueBand, VALUE_BANDS.find(v => v.id === selectedValueBand)?.name)}
                    </span>
                  </div>
                  <svg className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200 ${activeDropdown === "valueBand" ? "rotate-180 text-[#0055B8]" : ""}`} viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                  </svg>
                </button>

                {activeDropdown === "valueBand" && (
                  <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-2xl z-50 p-2 max-h-64 overflow-y-auto custom-scrollbar animate-fadeIn divide-y divide-slate-50">
                    <button
                      type="button"
                      onClick={() => { setSelectedValueBand("all"); setActiveDropdown(null); }}
                      className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                        selectedValueBand === "all" ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                      }`}
                    >
                      <span>{t("allValueBandsOpt")}</span>
                      {selectedValueBand === "all" && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                    </button>
                    {VALUE_BANDS.filter(v => v.id !== "all").map((band) => (
                      <button
                        key={band.id}
                        type="button"
                        onClick={() => { setSelectedValueBand(band.id); setActiveDropdown(null); }}
                        className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                          selectedValueBand === band.id ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                        }`}
                      >
                        <span className="truncate">{getValueBandName(band.id, band.name)}</span>
                        {selectedValueBand === band.id && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Modern Closing Window Dropdown */}
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setActiveDropdown(activeDropdown === "closing" ? null : "closing")}
                  className="w-full bg-[#F8FAFC] hover:bg-white border border-slate-200/90 hover:border-[#0055B8] focus:border-[#0055B8] focus:bg-white rounded-2xl p-3 sm:p-3.5 text-left transition-all hover:shadow-sm flex items-center justify-between gap-2 cursor-pointer"
                >
                  <div className="truncate">
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-0.5">
                      {t("deadlineLabel")}
                    </span>
                    <span className="text-xs sm:text-sm font-black text-[#0F172A] truncate block">
                      {getDeadlineName(closingWindow)}
                    </span>
                  </div>
                  <svg className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200 ${activeDropdown === "closing" ? "rotate-180 text-[#0055B8]" : ""}`} viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                  </svg>
                </button>

                {activeDropdown === "closing" && (
                  <div className="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-200 rounded-2xl shadow-2xl z-50 p-2 animate-fadeIn divide-y divide-slate-50">
                    {[
                      { id: "all" },
                      { id: "3days" },
                      { id: "7days" },
                      { id: "30days" },
                    ].map((opt) => (
                      <button
                        key={opt.id}
                        type="button"
                        onClick={() => { setClosingWindow(opt.id); setActiveDropdown(null); }}
                        className={`w-full text-left px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                          closingWindow === opt.id ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                        }`}
                      >
                        <span>{getDeadlineName(opt.id)}</span>
                        {closingWindow === opt.id && <span className="w-2 h-2 rounded-full bg-[#0055B8]" />}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Action Buttons: Search CTA & Clear Filters - Spans 2 cols on sm for balance */}
              <div className="flex gap-2 items-center sm:col-span-2 lg:col-span-1 xl:col-span-1">
                <button
                  type="button"
                  onClick={() => toast.info("Advance Search", "Use category, province and value filters for advanced search")}
                  className="hidden sm:inline-flex items-center gap-1 text-[10px] font-black uppercase tracking-wider text-slate-500 hover:text-[#0055B8] px-2.5 py-2.5 border border-slate-200 rounded-xl bg-white whitespace-nowrap min-h-[44px] shrink-0"
                >
                  {t("advanceSearch")}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    const el = document.getElementById("tender-results-section");
                    el?.scrollIntoView({ behavior: "smooth" });
                  }}
                  className="flex-1 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs sm:text-sm py-3 sm:py-3.5 lg:py-4 px-4 sm:px-5 rounded-xl sm:rounded-2xl transition-all hover:-translate-y-0.5 active:scale-95 shadow-md flex items-center justify-center gap-1.5 sm:gap-2 uppercase tracking-wider cursor-pointer min-h-[44px]"
                >
                  <span>{t("searchBtn")}</span>
                  <span>&rarr;</span>
                </button>

                {hasActiveFilters && (
                  <button
                    type="button"
                    onClick={handleReset}
                    title={t("resetBtn")}
                    className="p-3 sm:p-3.5 lg:p-4 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-600 rounded-xl sm:rounded-2xl transition-all hover:scale-105 active:scale-95 text-xs font-bold cursor-pointer min-h-[44px] min-w-[44px] flex items-center justify-center shrink-0"
                  >
                    {t("resetBtn")}
                  </button>
                )}
              </div>

            </div>

          </div>

        </div>
      </section>

      {/* Classic Live Count - Vintage Gazette Vibe (Desktop only) */}
      <div className="hidden lg:flex justify-center mb-8 xs:mb-10 sm:mb-12">
        <div className="inline-flex flex-wrap items-center justify-center gap-2 xs:gap-3 bg-[#FFFBEB] border-[3px] border-double border-[#B45309] px-4 xs:px-6 sm:px-8 py-3 xs:py-3.5 rounded-xl shadow-sm max-w-full">
          <span className="relative flex h-2.5 w-2.5 xs:h-3 xs:w-3 shrink-0">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2.5 w-2.5 xs:h-3 xs:w-3 bg-red-600 border-2 border-white shadow-xs"></span>
          </span>
          <span className="font-serif text-[10px] xs:text-xs sm:text-sm font-black tracking-[0.2em] text-[#92400E] uppercase">{t("liveTendersLabel")}</span>
          <span className="hidden xs:inline text-[#D97706] font-serif">—</span>
          <span className="font-display text-xl xs:text-2xl sm:text-3xl font-black text-[#0F172A] tracking-tight">{liveCount}</span>
          <span className="hidden xs:inline-flex items-center gap-1.5 text-[9px] xs:text-[10px] font-black tracking-widest text-[#B45309] uppercase border-l border-[#D97706]/30 pl-2 xs:pl-3 ml-1">
            <span className="hidden sm:inline">CLASSIC</span> LIVE
          </span>
        </div>
      </div>

      {/* 2. MAIN 2-COLUMN STRUCTURAL LAYOUT */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 xl:gap-10 items-start">
        
        {/* LEFT COLUMN: SIDEBAR BREAKDOWNS & TAXONOMY - Desktop only (Hidden on mobile to save vertical space) */}
        <aside className="hidden lg:flex lg:col-span-3 xl:col-span-3 flex-col space-y-4 xs:space-y-5 sm:space-y-6 lg:sticky lg:top-24 xl:top-28 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:custom-scrollbar lg:pr-1">
          
          {/* Spotlight 1: Prominent "Registration of Suppliers" Action Button */}
          <button
            type="button"
            onClick={() => handleStatusTabChange("suppliers")}
            className={`w-full p-4 rounded-2xl border-2 transition-all text-left shadow-md flex items-center justify-between cursor-pointer hover:-translate-y-0.5 active:scale-98 ${
              selectedCategory === "suppliers"
                ? "bg-[#0055B8] text-white border-[#0055B8] shadow-xl"
                : "bg-white text-slate-900 border-slate-200 hover:border-[#0055B8]"
            }`}
          >
            <div>
              <span className="text-[10px] font-black uppercase tracking-widest block opacity-80">
                {t("officialGazetteSpecialBadge")}
              </span>
              <span className="text-sm font-black block">
                {t("spotlightSuppliers")}
              </span>
            </div>
            <span className={`px-2.5 py-1 rounded-xl text-xs font-black font-mono ${
              selectedCategory === "suppliers" ? "bg-white/20 text-white" : "bg-[#EFF6FF] text-[#0055B8]"
            }`}>
              3,217
            </span>
          </button>

          {/* Spotlight 2: Publisher / Buyer Door */}
          <div className="bg-[#0F172A] text-white p-5 rounded-2xl shadow-lg border border-slate-700 hover:-translate-y-0.5 transition-all duration-200">
            <span className="text-[10px] uppercase font-black tracking-widest text-blue-300 block mb-1.5">
              {t("forProcuringBodiesBadge")}
            </span>
            <h2 className="text-sm font-black leading-tight mb-1.5 text-white">
              {t("publishFreeTitle")}
            </h2>
            <p className="text-xs text-slate-300 mb-4 font-normal leading-relaxed">
              {t("publishFreeSubtitle")}
            </p>
            <Link
              href="/register"
              className="block text-center bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs py-2.5 px-3 rounded-xl uppercase tracking-wider transition-all hover:-translate-y-0.5 active:scale-95 shadow-md"
            >
              {t("publishFreeBtn")}
            </Link>
          </div>

          {/* Taxonomy Section 1: Tenders By Sectors */}
          <div className="bg-white border border-slate-200/90 p-5 rounded-2xl shadow-md">
            <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
              <span className="text-xs font-black uppercase tracking-wider text-[#0F172A]">
                {t("tendersBySector")}
              </span>
              <span className="text-[11px] text-slate-400 font-mono">{totalCount}</span>
            </div>

            <nav className="flex flex-col gap-1.5 text-xs text-slate-700">
              {SECTORS.map((sec) => {
                const isSelected = sectorFilter === sec.id;
                return (
                  <button
                    key={sec.id}
                    type="button"
                    onClick={() => setSectorFilter(sec.id)}
                    className={`py-2 px-3 rounded-xl text-left flex items-center justify-between transition-all hover:translate-x-1 active:scale-98 cursor-pointer ${
                      isSelected
                        ? "bg-[#0055B8] text-white font-black shadow-sm"
                        : "hover:bg-slate-50 font-bold text-slate-700"
                    }`}
                  >
                    <span className="truncate pr-1">{getSectorName(sec.id, sec.name)}</span>
                    <span className={`font-mono text-[11px] ${isSelected ? "text-white" : "text-slate-400"}`}>
                      {sec.id === "all" ? totalCount : (sectorCounts[sec.id] || 0)}
                    </span>
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Taxonomy Section 2: Tenders By Categories */}
          <div className="bg-white border border-slate-200/90 p-5 rounded-2xl shadow-md">
            <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
              <span className="text-xs font-black uppercase tracking-wider text-[#0F172A]">
                {t("tendersByCategory")}
              </span>
              <span className="text-[11px] text-slate-400 font-mono">{t("twelveCategoriesLabel")}</span>
            </div>

            <nav className="flex flex-col gap-1.5 text-xs text-slate-700 max-h-[45vh] xs:max-h-64 sm:max-h-72 lg:max-h-80 xl:max-h-96 overflow-y-auto custom-scrollbar pr-1 overscroll-contain">
              <button
                type="button"
                onClick={() => setSelectedCategory("all")}
                className={`py-2.5 xs:py-2 px-3 rounded-xl text-left flex items-center justify-between transition-all hover:translate-x-1 active:scale-98 cursor-pointer min-h-[40px] xs:min-h-0 ${
                  selectedCategory === "all"
                    ? "bg-[#0055B8] text-white font-black shadow-sm"
                    : "hover:bg-slate-50 active:bg-slate-100 font-bold text-slate-700"
                }`}
              >
                <span>{t("allCategoriesLabel")}</span>
                <span className="font-mono text-[11px] opacity-80">{totalCount}</span>
              </button>

              {CATEGORIES.map((cat) => {
                const isSelected = selectedCategory === cat.id;
                return (
                  <button
                    key={cat.id}
                    type="button"
                    onClick={() => setSelectedCategory(cat.id)}
                    className={`py-2.5 xs:py-2 px-3 rounded-xl text-left flex items-center justify-between transition-all hover:translate-x-1 active:scale-98 cursor-pointer min-h-[40px] xs:min-h-0 ${
                      isSelected
                        ? "bg-[#0055B8] text-white font-black shadow-sm"
                        : "hover:bg-slate-50 active:bg-slate-100 font-bold text-slate-700"
                    }`}
                  >
                    <span className="truncate pr-1">{getCategoryName(cat.id, cat.name)}</span>
                    <span className={`font-mono text-[11px] ${isSelected ? "text-white" : "text-slate-400"}`}>
                      {categoryCounts[cat.id] || 0}
                    </span>
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Taxonomy Section 3: Tenders By Locations */}
          <div className="bg-white border border-slate-200/90 p-5 rounded-2xl shadow-md">
            <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
              <span className="text-xs font-black uppercase tracking-wider text-[#0F172A]">
                {t("tendersByLocations")}
              </span>
              <span className="text-[11px] text-slate-400 font-mono">9 Provinces</span>
            </div>

            <nav className="flex flex-col gap-1.5 text-xs text-slate-700 max-h-[40vh] xs:max-h-56 sm:max-h-64 lg:max-h-72 xl:max-h-80 overflow-y-auto custom-scrollbar pr-1 overscroll-contain">
              <button
                type="button"
                onClick={() => setSelectedProvince("all")}
                className={`py-2.5 xs:py-2 px-3 rounded-xl text-left flex items-center justify-between transition-all hover:translate-x-1 active:scale-98 cursor-pointer min-h-[40px] xs:min-h-0 ${
                  selectedProvince === "all"
                    ? "bg-[#0055B8] text-white font-black shadow-sm"
                    : "hover:bg-slate-50 active:bg-slate-100 font-bold text-slate-700"
                }`}
              >
                <span>{t("allProvincesOpt")}</span>
                <span className="font-mono text-[11px] opacity-80">{totalCount}</span>
              </button>

              {PROVINCES.filter(p => p.id !== "all").map((prov) => {
                const isSelected = selectedProvince === prov.id;
                const provCount = provinceCounts[prov.id] ?? 0;
                return (
                  <button
                    key={prov.id}
                    type="button"
                    onClick={() => setSelectedProvince(prov.id)}
                    className={`py-2.5 xs:py-2 px-3 rounded-xl text-left flex items-center justify-between transition-all hover:translate-x-1 active:scale-98 cursor-pointer min-h-[40px] xs:min-h-0 ${
                      isSelected
                        ? "bg-[#0055B8] text-white font-black shadow-sm"
                        : "hover:bg-slate-50 active:bg-slate-100 font-bold text-slate-700"
                    }`}
                  >
                    <span className="truncate pr-1">{getProvinceName(prov.id, prov.name)}</span>
                    <span className={`font-mono text-[11px] ${isSelected ? "text-white" : "text-slate-400"}`}>
                      {provCount}
                    </span>
                  </button>
                );
              })}
            </nav>
          </div>

        </aside>

        {/* RIGHT COLUMN: DIRECT TENDER RESULTS */}
        <main className="lg:col-span-9 xl:col-span-9 w-full min-w-0">

          {/* MOBILE SLIDE-OUT OFF-CANVAS SIDE MENU DRAWER */}
          {mounted && isMobileSideMenuOpen && createPortal(
            <div className="fixed inset-0 z-[99999] lg:hidden flex justify-start">
              {/* Backdrop Overlay */}
              <div
                className="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity animate-fadeIn"
                onClick={() => setIsMobileSideMenuOpen(false)}
                aria-hidden="true"
              />
              
              {/* Off-canvas Drawer Panel (Slide from Left, 100dvh full height) */}
              <div className="relative w-full max-w-[85vw] sm:max-w-sm h-[100dvh] max-h-[100dvh] bg-white shadow-2xl flex flex-col z-[100000] animate-slideRight pb-[env(safe-area-inset-bottom,0px)]">
                
                {/* Drawer Header */}
                <div className="p-4 xs:p-5 border-b border-slate-100 flex items-center justify-between bg-[#0A1633] text-white shrink-0">
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse shrink-0" />
                    <span className="font-display text-base xs:text-lg font-black uppercase tracking-wider truncate">
                      {t("tendersByCategory")} &amp; Filters
                    </span>
                  </div>
                  <button
                    type="button"
                    onClick={() => setIsMobileSideMenuOpen(false)}
                    aria-label="Close Side Menu"
                    className="p-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white transition-all cursor-pointer shrink-0"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" strokeWidth="2.5" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                {/* Drawer Body (Scrollable) */}
                <div className="flex-1 overflow-y-auto custom-scrollbar p-4 xs:p-5 space-y-5">
                  
                  {/* Spotlight Badges inside Side Menu */}
                  <div className="space-y-2">
                    <button
                      type="button"
                      onClick={() => {
                        handleStatusTabChange("suppliers");
                        setIsMobileSideMenuOpen(false);
                      }}
                      className={`w-full p-3 rounded-xl border transition-all text-left shadow-xs flex items-center justify-between cursor-pointer ${
                        selectedCategory === "suppliers"
                          ? "bg-[#0055B8] text-white border-[#0055B8]"
                          : "bg-slate-50 text-slate-900 border-slate-200"
                      }`}
                    >
                      <div className="min-w-0 pr-2">
                        <span className="text-[9px] font-black uppercase tracking-wider block opacity-75">{t("officialGazetteSpecialBadge")}</span>
                        <span className="text-xs font-black block truncate">{t("spotlightSuppliers")}</span>
                      </div>
                      <span className={`px-2 py-0.5 rounded-lg text-xs font-black font-mono shrink-0 ${
                        selectedCategory === "suppliers" ? "bg-white/20 text-white" : "bg-[#EFF6FF] text-[#0055B8]"
                      }`}>
                        3,217
                      </span>
                    </button>

                    <Link
                      href="/register"
                      onClick={() => setIsMobileSideMenuOpen(false)}
                      className="p-3 rounded-xl bg-[#0F172A] text-white border border-slate-700 shadow-xs flex items-center justify-between transition-all block"
                    >
                      <div className="min-w-0 pr-2">
                        <span className="text-[9px] font-black uppercase tracking-wider text-blue-300 block">{t("forProcuringBodiesBadge")}</span>
                        <span className="text-xs font-black block truncate">{t("publishFreeTitle")}</span>
                      </div>
                      <span className="px-2.5 py-1 rounded-lg bg-[#0055B8] text-white text-[10px] font-black uppercase tracking-wider shrink-0">
                        + FREE
                      </span>
                    </Link>
                  </div>

                  {/* Status Tabs in Side Menu */}
                  <div>
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-2">
                      Tender Status
                    </span>
                    <div className="grid grid-cols-2 gap-1.5">
                      {[
                        { id: "live", label: t("liveTendersLabel"), count: String(liveCount) },
                        { id: "today", label: t("todaysTendersLabel"), count: String(todayCount) },
                        { id: "closing", label: t("statusClosing"), count: String(closingSoonCount) },
                        { id: "closed", label: t("closedTendersLabel"), count: String(closedCount) },
                        { id: "all", label: t("allTendersLabel"), count: String(totalCount) },
                        { id: "suppliers", label: t("statusSuppliers"), count: String(supplierCount) },
                      ].map((tab) => {
                        const isActive = statusTab === tab.id;
                        return (
                          <button
                            key={tab.id}
                            type="button"
                            onClick={() => {
                              handleStatusTabChange(tab.id as any);
                              setIsMobileSideMenuOpen(false);
                            }}
                            className={`p-2.5 rounded-xl text-xs font-black text-left flex items-center justify-between transition-all cursor-pointer ${
                              isActive
                                ? "bg-[#0055B8] text-white shadow-sm"
                                : "bg-slate-50 text-slate-700 hover:bg-slate-100"
                            }`}
                          >
                            <span className="truncate">{tab.label}</span>
                            <span className={`text-[10px] font-mono shrink-0 ${isActive ? "text-white/80" : "text-slate-500"}`}>{tab.count}</span>
                          </button>
                        );
                      })}
                    </div>
                  </div>

                  {/* Categories Breakdown List inside Side Menu */}
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-[10px] font-black uppercase tracking-wider text-slate-400">
                        {t("tendersByCategory")}
                      </span>
                      <span className="text-[10px] text-slate-400 font-mono">12 Categories</span>
                    </div>
                    
                    <div className="space-y-1 max-h-60 overflow-y-auto custom-scrollbar pr-1">
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedCategory("all");
                          setIsMobileSideMenuOpen(false);
                        }}
                        className={`w-full py-2 px-3 rounded-xl text-left flex items-center justify-between text-xs font-bold transition-all cursor-pointer ${
                          selectedCategory === "all"
                            ? "bg-[#0055B8] text-white font-black shadow-xs"
                            : "bg-slate-50 text-slate-700 hover:bg-slate-100"
                        }`}
                      >
                        <span>{t("allCategoriesLabel")}</span>
                        <span className={`font-mono text-[11px] ${selectedCategory === "all" ? "text-white/80" : "text-slate-400"}`}>{totalCount}</span>
                      </button>

                      {CATEGORIES.map((cat) => {
                        const isSelected = selectedCategory === cat.id;
                        return (
                          <button
                            key={cat.id}
                            type="button"
                            onClick={() => {
                              setSelectedCategory(cat.id);
                              setIsMobileSideMenuOpen(false);
                            }}
                            className={`w-full py-2 px-3 rounded-xl text-left flex items-center justify-between text-xs font-bold transition-all cursor-pointer ${
                              isSelected
                                ? "bg-[#0055B8] text-white font-black shadow-xs"
                                : "hover:bg-slate-100 text-slate-700"
                            }`}
                          >
                            <span className="truncate pr-2">{getCategoryName(cat.id, cat.name)}</span>
                            <span className={`font-mono text-[11px] ${isSelected ? "text-white/80" : "text-slate-400"}`}>{categoryCounts[cat.id] || 0}</span>
                          </button>
                        );
                      })}
                    </div>
                  </div>

                  {/* Sectors in Side Menu */}
                  <div>
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-2">
                      {t("tendersBySector")}
                    </span>
                    <div className="space-y-1">
                      {SECTORS.map((sec) => (
                        <button
                          key={sec.id}
                          type="button"
                          onClick={() => {
                            setSectorFilter(sec.id);
                            setIsMobileSideMenuOpen(false);
                          }}
                          className={`w-full py-2 px-3 rounded-xl text-left flex items-center justify-between text-xs font-bold transition-all cursor-pointer ${
                            sectorFilter === sec.id
                              ? "bg-[#0055B8] text-white font-black"
                              : "hover:bg-slate-100 text-slate-700"
                          }`}
                        >
                          <span className="truncate pr-2">{getSectorName(sec.id, sec.name)}</span>
                          <span className={`font-mono text-[11px] ${sectorFilter === sec.id ? "text-white/80" : "text-slate-400"}`}>{sec.id === "all" ? totalCount : (sectorCounts[sec.id] || 0)}</span>
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Provinces in Side Menu */}
                  <div>
                    <span className="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-2">
                      {t("tendersByLocations")}
                    </span>
                    <div className="grid grid-cols-2 gap-1.5 max-h-48 overflow-y-auto custom-scrollbar pr-1">
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedProvince("all");
                          setIsMobileSideMenuOpen(false);
                        }}
                        className={`py-2 px-2.5 rounded-xl text-left text-xs font-bold transition-all cursor-pointer truncate ${
                          selectedProvince === "all" ? "bg-[#0055B8] text-white font-black" : "bg-slate-50 text-slate-700"
                        }`}
                      >
                        {t("allProvincesOpt")}
                      </button>
                      {PROVINCES.filter(p => p.id !== "all").map((prov) => (
                        <button
                          key={prov.id}
                          type="button"
                          onClick={() => {
                            setSelectedProvince(prov.id);
                            setIsMobileSideMenuOpen(false);
                          }}
                          className={`py-2 px-2.5 rounded-xl text-left text-xs font-bold transition-all cursor-pointer truncate ${
                            selectedProvince === prov.id ? "bg-[#0055B8] text-white font-black" : "bg-slate-50 text-slate-700"
                          }`}
                        >
                          {getProvinceName(prov.id, prov.name)}
                        </button>
                      ))}
                    </div>
                  </div>

                </div>

                {/* Drawer Footer Actions */}
                <div className="p-4 border-t border-slate-100 bg-slate-50 flex gap-2 shrink-0">
                  <button
                    type="button"
                    onClick={() => {
                      handleReset();
                      setIsMobileSideMenuOpen(false);
                    }}
                    className="flex-1 py-3 px-3 rounded-xl bg-white border border-slate-200 text-slate-700 font-black text-xs uppercase tracking-wider cursor-pointer text-center shadow-2xs"
                  >
                    {t("resetBtn")}
                  </button>
                  <button
                    type="button"
                    onClick={() => setIsMobileSideMenuOpen(false)}
                    className="flex-2 py-3 px-4 rounded-xl bg-[#0055B8] text-white font-black text-xs uppercase tracking-wider cursor-pointer text-center shadow-md"
                  >
                    Apply ({filteredTenders.length}) &rarr;
                  </button>
                </div>

              </div>
            </div>,
            document.body
          )}

          {/* 3A. MOBILE/TABLET ONLY: TENDERS BY CATEGORY (12) SIDE MENU TRIGGER BUTTON */}
          <div className="lg:hidden mb-5 xs:mb-6 flex items-center">
            <button
              type="button"
              onClick={() => setIsMobileSideMenuOpen(true)}
              className="px-4 xs:px-5 py-2.5 xs:py-3 bg-[#0F172A] hover:bg-slate-800 active:scale-[0.98] text-white font-black text-xs sm:text-sm rounded-xl xs:rounded-2xl shadow-sm transition-all flex items-center gap-2.5 xs:gap-3 cursor-pointer"
            >
              <svg className="w-4 h-4 text-blue-400 shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="2.5" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
              </svg>
              <span className="uppercase tracking-wider font-extrabold text-xs xs:text-sm text-white">
                {selectedCategory === "all" ? t("tendersByCategory") : getCategoryName(selectedCategory, CATEGORIES.find(c => c.id === selectedCategory)?.name)}
              </span>
              <span className="px-2 py-0.5 rounded-lg text-[11px] xs:text-xs font-mono font-bold bg-[#1E293B] text-slate-200 border border-slate-700/60">
                12
              </span>
            </button>
          </div>

          {/* 3B. DESKTOP ONLY: QUICK STATUS TABS RIBBON (Classic Full Ribbon for Large Screens) */}
          <div className="hidden lg:flex bg-white border border-slate-200/90 rounded-2xl p-1.5 xs:p-2 sm:p-2.5 mb-6 xs:mb-8 sm:mb-10 shadow-sm items-center gap-1.5 xs:gap-2 overflow-x-auto no-scrollbar">
            {[
              { id: "live", label: t("liveTendersLabel"), count: String(liveCount) },
              { id: "today", label: t("todaysTendersLabel"), count: String(todayCount) },
              { id: "closed", label: t("closedTendersLabel"), count: String(closedCount) },
              { id: "all", label: t("allTendersLabel"), count: String(totalCount) },
              { id: "suppliers", label: t("statusSuppliers"), count: String(supplierCount) },
              { id: "closing", label: t("statusClosing"), count: String(closingSoonCount) },
            ].map((tab) => {
              const isActive = statusTab === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => handleStatusTabChange(tab.id as any)}
                  className={`px-3.5 xs:px-4 py-2 xs:py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all flex items-center gap-2 shrink-0 cursor-pointer min-h-[38px] ${
                    isActive
                      ? "bg-[#0055B8] text-white shadow-md"
                      : "text-slate-700 hover:bg-slate-100 active:bg-slate-200 font-bold"
                  }`}
                >
                  <span className="whitespace-nowrap shrink-0">{tab.label}</span>
                  <span className={`px-2 py-0.5 rounded-lg text-[10px] font-mono shrink-0 whitespace-nowrap font-bold ${
                    isActive ? "bg-white/20 text-white" : "bg-[#F1F5F9] text-slate-600"
                  }`}>
                    {tab.count}
                  </span>
                </button>
              );
            })}
          </div>

          {/* 4. RESULTS HEADER & CONTROLS */}
          <section id="tender-results-section" className="mb-8">
            <div className="flex flex-wrap items-center justify-between gap-4 pb-5 border-b border-slate-200">
              
              <div className="flex items-baseline gap-2 xs:gap-3 min-w-0 flex-1">
                <h2 className="text-lg xs:text-xl sm:text-2xl font-black text-[#0F172A] tracking-tight truncate">
                  {t("resultsHeaderTitle")} ({filteredTenders.length})
                </h2>
                <span className="text-xs text-slate-500 font-medium hidden lg:inline truncate">
                  {t("resultsHeaderSubtitle")}
                </span>
              </div>

              {/* View Switcher & Sort Selector - Stacks on 320px, row on 375px+ */}
              <div className="flex flex-col xs:flex-row items-stretch xs:items-center gap-2 xs:gap-3 w-full sm:w-auto">
                
                {/* Modern Capsule View Switcher (Rule #8) - Full width on xs */}
                <div className="inline-flex p-1 bg-[#F1F5F9] rounded-xl border border-[#E2E8F0] shadow-2xs w-full xs:w-auto self-stretch xs:self-auto">
                  <button
                    type="button"
                    onClick={() => setViewMode("cards")}
                    className={`flex-1 xs:flex-none px-3 xs:px-3.5 py-2 xs:py-1.5 text-xs font-black rounded-lg transition-all flex items-center justify-center gap-1.5 cursor-pointer min-h-[36px] xs:min-h-0 ${
                      viewMode === "cards"
                        ? "bg-[#0055B8] text-white shadow-xs"
                        : "text-slate-600 hover:text-black font-bold"
                    }`}
                  >
                    <span>{t("cardGridViewBtn")}</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setViewMode("list")}
                    className={`flex-1 xs:flex-none px-3 xs:px-3.5 py-2 xs:py-1.5 text-xs font-black rounded-lg transition-all flex items-center justify-center gap-1.5 cursor-pointer min-h-[36px] xs:min-h-0 ${
                      viewMode === "list"
                        ? "bg-[#0055B8] text-white shadow-xs"
                        : "text-slate-600 hover:text-black font-bold"
                    }`}
                  >
                    <span>{t("denseListViewBtn")}</span>
                  </button>
                </div>

                {/* Modern Sort Dropdown - Full width on xs */}
                <div className="relative w-full xs:w-auto" ref={sortDropdownRef}>
                  <button
                    type="button"
                    onClick={() => setActiveDropdown(activeDropdown === "sort" ? null : "sort")}
                    className="w-full xs:w-auto bg-[#F8FAFC] hover:bg-white border border-slate-200 hover:border-[#0055B8] rounded-xl px-3 py-2.5 xs:py-2 text-xs font-bold text-[#0F172A] transition-all flex items-center justify-between xs:justify-start gap-2 cursor-pointer shadow-2xs min-h-[44px] xs:min-h-0"
                  >
                    <span className="text-slate-400 font-normal hidden xs:inline">{t("sortLabel")}</span>
                    <span className="font-black truncate">
                      {sortBy === "closing" ? t("sortClosingSoon") : sortBy === "newest" ? t("sortRecentlyPublished") : sortBy === "amountDesc" ? t("sortValueHighToLow") : t("sortValueLowToHigh")}
                    </span>
                    <svg className="w-3.5 h-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                      <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                    </svg>
                  </button>

                  {activeDropdown === "sort" && (
                    <div className="absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-2xl shadow-2xl z-50 p-2 animate-fadeIn divide-y divide-slate-50">
                      {[
                        { id: "closing", label: t("closingSoonUrgent") },
                        { id: "newest", label: t("newestPublished") },
                        { id: "amountDesc", label: t("highestBudget") },
                        { id: "amountAsc", label: t("lowestBudget") },
                      ].map((item) => (
                        <button
                          key={item.id}
                          type="button"
                          onClick={() => { setSortBy(item.id); setActiveDropdown(null); }}
                          className={`w-full text-left px-3 py-2 rounded-xl text-xs font-bold transition-all flex items-center justify-between cursor-pointer ${
                            sortBy === item.id ? "bg-[#EFF6FF] text-[#0055B8] font-black" : "text-slate-700 hover:bg-slate-50"
                          }`}
                        >
                          <span>{item.label}</span>
                          {sortBy === item.id && <span className="w-1.5 h-1.5 rounded-full bg-[#0055B8]" />}
                        </button>
                      ))}
                    </div>
                  )}
                </div>

              </div>

            </div>
          </section>

          {/* 5. TENDER CATALOGUE: CARDS GRID VS DENSE LIST */}
          {viewMode === "cards" ? (
            <section className="grid grid-cols-1 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2 2xl:grid-cols-2 gap-4 xs:gap-5 sm:gap-6 mb-8 items-stretch">
              {paginatedTenders.map((tender) => {
                const isSaved = savedTenders.has(tender.id);

                return (
                  <Link
                    key={tender.id}
                    href={tender.instrumentType === "Auction" ? `/auctions/${tender.id}` : `/tenders/${tender.id}`}
                    className="bg-white rounded-xl xs:rounded-2xl p-4 xs:p-5 sm:p-6 flex flex-col justify-between shadow-md hover:shadow-xl sm:hover:shadow-2xl hover:-translate-y-1 sm:hover:-translate-y-2 hover:scale-[1.005] sm:hover:scale-[1.01] active:scale-[0.99] transition-all duration-300 cursor-pointer group min-h-[280px] xs:min-h-[290px] no-underline block transform-gpu border border-slate-200/90 sm:border-2 hover:border-[#0055B8] overflow-hidden"
                  >
                    <div className="min-w-0">
                      {/* Top Authority & Urgency Row - Wraps on tiny screens */}
                      <div className="flex flex-wrap xs:flex-nowrap items-center justify-between gap-2 pb-3 mb-3 xs:mb-3.5 border-b border-slate-100 text-xs">
                        <span className="font-extrabold text-[#0055B8] uppercase tracking-wider truncate text-[10px] xs:text-[11px] min-w-0 flex-1 xs:flex-initial">
                          {tender.entity}
                        </span>
                        <div className="flex items-center gap-1.5 shrink-0 w-full xs:w-auto justify-between xs:justify-end">
                          <span className="px-2.5 xs:px-3 py-1 rounded-lg xs:rounded-xl text-[11px] xs:text-xs font-bold bg-[#F1F5F9] text-[#0055B8] border border-[#E2E8F0] shadow-2xs group-hover:bg-blue-50/80 transition-colors">
                            {tender.daysLeft}{t("daysLeftText")}
                          </span>
                          <button
                            type="button"
                            onClick={(e) => toggleBookmark(e, tender.id)}
                            aria-label={isSaved ? "Remove from watchlist" : "Save to watchlist"}
                            title={isSaved ? "Remove from watchlist" : "Save to watchlist"}
                            className="p-2 xs:p-1.5 rounded-lg bg-[#F1F5F9] hover:bg-white border border-[#E2E8F0] text-slate-400 hover:text-[#0055B8] transition-all hover:scale-110 active:scale-90 shadow-2xs cursor-pointer min-h-[36px] min-w-[36px] xs:min-h-0 xs:min-w-0 flex items-center justify-center shrink-0"
                          >
                            <svg 
                              className={`w-3.5 h-3.5 ${isSaved ? "fill-[#0055B8] text-[#0055B8]" : "fill-none text-slate-400"}`} 
                              viewBox="0 0 24 24" 
                              stroke="currentColor" 
                              strokeWidth={2}
                            >
                              <path strokeLinecap="round" strokeLinejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                            </svg>
                          </button>
                        </div>
                      </div>

                      {/* Main Title */}
                      <h3 className="text-[15px] sm:text-base font-extrabold text-[#0F172A] leading-snug mb-3 group-hover:text-[#0055B8] transition-colors line-clamp-2">
                        {tender.title}
                      </h3>

                      {/* Organization - Login gated */}
                      <div className="text-xs font-bold text-[#0055B8] mb-3 flex items-center gap-2">
                        <span className="text-slate-400 font-semibold">{isLoggedIn ? tender.entity : t("cardLoginToView") + " " + t("cardCategory").replace(":","")}</span>
                        {!isLoggedIn && (
                          <button
                            type="button"
                            onClick={(e) => {
                              e.preventDefault();
                              e.stopPropagation();
                              router.push("/login");
                            }}
                            className="text-[10px] bg-[#EFF6FF] border border-[#BFDBFE] text-[#0055B8] px-2 py-0.5 rounded-lg font-black hover:bg-blue-100 cursor-pointer"
                          >
                            {t("cardLoginToView")}
                          </button>
                        )}
                      </div>

                      {/* Detailed Meta - TenderNotices.lk style - Responsive for 320px -> 1920px */}
                      <div className="space-y-1.5 xs:space-y-1 text-[11px] xs:text-xs mb-4 bg-[#F8FAFC] p-2.5 xs:p-3 rounded-xl border border-slate-100 min-w-0">
                        <div className="flex flex-col xs:flex-row xs:items-center gap-0.5 xs:gap-2"><span className="font-bold text-slate-500 w-auto xs:w-20 sm:w-24 lg:w-28 shrink-0 text-[10px] xs:text-[11px]">{t("cardCategory")}</span><span className="bg-white text-[#0055B8] border border-[#E2E8F0] px-2 py-0.5 rounded-lg font-bold text-[10px] xs:text-[11px] inline-flex w-fit">{tender.categoryName}</span></div>
                        <div className="flex flex-col xs:flex-row xs:items-center gap-0.5 xs:gap-2">
                          <span className="font-bold text-slate-500 w-auto xs:w-20 sm:w-24 lg:w-28 shrink-0 text-[10px] xs:text-[11px]">{t("cardSource")}</span>
                          {isLoggedIn ? (
                            <span className="text-slate-700 font-semibold truncate text-[11px] xs:text-xs">{tender.source}</span>
                          ) : (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                router.push("/login");
                              }}
                              className="text-[#0055B8] font-bold hover:underline text-[11px] w-fit text-left cursor-pointer"
                            >
                              {t("cardLoginToView")}
                            </button>
                          )}
                        </div>
                        <div className="flex flex-col xs:flex-row xs:items-center gap-0.5 xs:gap-2"><span className="font-bold text-slate-500 w-auto xs:w-20 sm:w-24 lg:w-28 shrink-0 text-[10px] xs:text-[11px]">{t("cardLocation")}</span><span className="text-slate-700 font-semibold truncate text-[11px] xs:text-xs">{tender.location}</span></div>
                        <div className="flex flex-col xs:flex-row xs:items-center gap-0.5 xs:gap-2">
                          <span className="font-bold text-slate-500 w-auto xs:w-20 sm:w-24 lg:w-28 shrink-0 text-[10px] xs:text-[11px]">{t("cardPublishedDate")}</span>
                          {isLoggedIn ? (
                            <span className="text-slate-700 text-[11px] xs:text-xs">{tender.startDate}</span>
                          ) : (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                router.push("/login");
                              }}
                              className="text-[#0055B8] font-bold hover:underline text-[11px] w-fit text-left cursor-pointer"
                            >
                              {t("cardLoginToView")}
                            </button>
                          )}
                        </div>
                        <div className="flex flex-col xs:flex-row xs:items-center gap-0.5 xs:gap-2"><span className="font-bold text-slate-500 w-auto xs:w-20 sm:w-24 lg:w-28 shrink-0 text-[10px] xs:text-[11px]">{t("cardClosingDate")}</span><span className="text-slate-700 font-bold text-[11px] xs:text-xs">{tender.endDate}</span></div>
                        <div className="flex flex-col xs:flex-row xs:items-center gap-0.5 xs:gap-2"><span className="font-bold text-slate-500 w-auto xs:w-20 sm:w-24 lg:w-28 shrink-0 text-[10px] xs:text-[11px]">{t("cardReferenceNo")}</span><span className="font-mono text-[#0055B8] font-bold text-[11px] xs:text-xs break-all">{tender.ref}</span></div>
                        <div className="flex flex-wrap items-center gap-1.5 xs:gap-2 pt-1"><span className="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg font-black text-[9px] xs:text-[10px]">{t("cardLiveTender")}</span><span className="text-[#0055B8] font-bold text-[10px] xs:text-[11px]">{t("cardTenderClosingIn")} {tender.daysLeft} {t("cardDays")}</span></div>
                      </div>
                    </div>

                    {/* Bottom Budget & Action - Stacks on 320px */}
                    <div className="pt-3 xs:pt-3.5 border-t border-slate-100 flex flex-col xs:flex-row xs:items-center justify-between gap-3 xs:gap-2 mt-2">
                      <div className="min-w-0">
                        <div className="text-[9px] xs:text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-0.5">
                          {t("budgetEstimateLabel")}
                        </div>
                        <div className="text-sm xs:text-base sm:text-lg font-black text-[#0F172A] font-mono tracking-tight truncate">
                          {tender.amount}
                        </div>
                      </div>
                      
                      <span className="inline-flex items-center justify-center gap-1.5 px-4 xs:px-3.5 py-2.5 xs:py-1.5 bg-[#0055B8] group-hover:bg-[#004394] text-white font-bold text-xs rounded-lg xs:rounded-xl border border-[#0055B8] transition-all duration-200 shadow-xs group-hover:translate-x-1 group-hover:shadow-md w-full xs:w-auto min-h-[40px] xs:min-h-0">
                        <span>{t("cardClickToView")}</span>
                        <span className="group-hover:translate-x-0.5 transition-transform">&rarr;</span>
                      </span>
                    </div>
                  </Link>
                );
              })}
            </section>
          ) : (
            /* DENSE TABLE VIEW WITH DIRECT FULL PAGE LINK - Responsive: scroll on mobile, optimized for tablet/desktop */
            <section className="bg-white border border-slate-200/90 rounded-xl xs:rounded-2xl overflow-hidden mb-8 shadow-md sm:shadow-lg -mx-3 xs:mx-0">
              <div className="overflow-x-auto custom-scrollbar overscroll-x-contain">
                <table className="w-full text-left text-[11px] xs:text-xs sm:text-sm border-collapse min-w-[640px] sm:min-w-0">
                  <thead className="bg-[#F8FAFC] border-b border-slate-200 text-slate-500 font-black uppercase tracking-wider text-[10px] xs:text-[11px]">
                    <tr>
                      <th className="px-3 xs:px-4 sm:px-5 py-3 xs:py-4 w-[22%] min-w-[140px] xs:min-w-[180px] align-middle">{t("tableEntityCol")}</th>
                      <th className="px-3 xs:px-4 sm:px-5 py-3 xs:py-4 w-[32%] min-w-[180px] xs:min-w-[240px] align-middle">{t("tableTitleCol")}</th>
                      <th className="px-3 xs:px-4 sm:px-5 py-3 xs:py-4 w-[18%] min-w-[120px] xs:min-w-[170px] align-middle text-center hidden sm:table-cell">{t("tableCategoryCol")}</th>
                      <th className="px-3 xs:px-4 sm:px-5 py-3 xs:py-4 w-[14%] min-w-[100px] xs:min-w-[140px] align-middle text-center">{t("tableClosingCol")}</th>
                      <th className="px-3 xs:px-4 sm:px-5 py-3 xs:py-4 w-[10%] min-w-[90px] xs:min-w-[110px] align-middle text-right hidden md:table-cell">{t("tableValueCol")}</th>
                      <th className="px-3 xs:px-4 sm:px-5 py-3 xs:py-4 w-[4%] min-w-[80px] xs:min-w-[90px] align-middle text-center">{t("tableActionCol")}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 font-medium text-slate-900">
                    {paginatedTenders.map((tender) => {
                      const isSaved = savedTenders.has(tender.id);
                      return (
                        <tr 
                          key={tender.id}
                          onClick={() => router.push(tender.instrumentType === "Auction" ? `/auctions/${tender.id}` : `/tenders/${tender.id}`)}
                          className="hover:bg-blue-50/40 cursor-pointer transition-colors duration-150"
                        >
                          <td className="px-5 py-4 align-middle">
                            <div className="font-extrabold text-[#0055B8] leading-snug">{tender.entity}</div>
                            <div className="text-[11px] text-slate-400 font-mono mt-0.5">{tender.ref}</div>
                          </td>
                          
                          <td className="px-5 py-4 align-middle font-black text-[#0F172A] leading-snug">
                            <Link href={tender.instrumentType === "Auction" ? `/auctions/${tender.id}` : `/tenders/${tender.id}`} className="hover:text-[#0055B8] transition-colors block">
                              {tender.title}
                            </Link>
                          </td>
                          
                          <td className="px-5 py-4 align-middle text-center whitespace-nowrap">
                            <div className="font-bold text-[#0F172A] text-xs">
                              {tender.categoryName}
                            </div>
                            <div className="text-[11px] text-slate-400 font-medium mt-0.5">
                              {tender.district}
                            </div>
                          </td>
                          
                          <td className="px-5 py-4 align-middle text-center whitespace-nowrap font-mono">
                            <div className="font-mono font-bold text-[#0F172A] text-xs">
                              {tender.endDate}
                            </div>
                            <div className="font-mono text-[11px] font-bold text-[#0055B8] mt-0.5">
                              {tender.daysLeft}{t("daysLeftText")}
                            </div>
                          </td>
                          
                          <td className="px-5 py-4 align-middle font-mono font-black text-right text-[#0F172A] whitespace-nowrap text-sm">
                            {tender.amount}
                          </td>
                          
                          <td className="px-5 py-4 align-middle text-center whitespace-nowrap">
                            <div className="flex items-center justify-center gap-2" onClick={(e) => e.stopPropagation()}>
                              <button
                                type="button"
                                onClick={(e) => toggleBookmark(e, tender.id)}
                                aria-label={isSaved ? "Remove from watchlist" : "Save to watchlist"}
                                title={isSaved ? "Remove from watchlist" : "Save to watchlist"}
                                className="p-2 rounded-xl bg-[#F1F5F9] hover:bg-white border border-[#E2E8F0] text-slate-400 hover:text-[#0055B8] transition-all hover:scale-105 active:scale-95 shadow-2xs cursor-pointer"
                              >
                                <svg 
                                  className={`w-3.5 h-3.5 ${isSaved ? "fill-[#0055B8] text-[#0055B8]" : "fill-none text-slate-400"}`} 
                                  viewBox="0 0 24 24" 
                                  stroke="currentColor" 
                                  strokeWidth={2}
                                >
                                  <path strokeLinecap="round" strokeLinejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                                </svg>
                              </button>
                              <Link 
                                href={tender.instrumentType === "Auction" ? `/auctions/${tender.id}` : `/tenders/${tender.id}`}
                                className="px-3.5 py-1.5 bg-[#0055B8] hover:bg-[#004394] text-white font-black text-xs rounded-xl shadow-xs transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider"
                              >
                                {t("viewDetailsBtn")} &rarr;
                              </Link>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          {/* 6. MODERN LOAD BALANCED PAGINATION CONTROL BAR - Fully responsive 320px -> 1920px+ */}
          <div className="bg-white border border-slate-200/90 rounded-xl xs:rounded-2xl p-3 xs:p-4 sm:p-5 shadow-sm flex flex-col gap-3 xs:gap-4 sm:flex-row sm:items-center sm:justify-between mb-12 xs:mb-16">
            <div className="text-[11px] xs:text-xs text-slate-500 font-medium text-center xs:text-left order-2 sm:order-1">
              {t("paginationShowing")} <strong className="font-bold text-[#0F172A]">{startIndex}</strong> {t("paginationTo")} <strong className="font-bold text-[#0F172A]">{endIndex}</strong> {t("paginationOf")} <strong className="font-bold text-[#0055B8]">{filteredTenders.length}</strong> {t("paginationNotices")}
            </div>

            {/* Page Navigation Switcher - Scrollable on 320px, centered */}
            <div className="flex items-center gap-1 xs:gap-1.5 self-center sm:self-auto order-1 sm:order-2 max-w-full overflow-x-auto custom-scrollbar pb-1 xs:pb-0 px-1 xs:px-0">
              <button
                type="button"
                disabled={currentPage <= 1}
                onClick={() => handlePageChange(currentPage - 1)}
                className="px-2.5 xs:px-3 py-2 xs:py-1.5 bg-[#F1F5F9] hover:bg-slate-200 active:bg-slate-300 disabled:opacity-40 disabled:pointer-events-none text-slate-700 font-bold text-[11px] xs:text-xs rounded-lg xs:rounded-xl transition-all cursor-pointer shadow-2xs shrink-0 min-h-[36px] xs:min-h-0"
              >
                &larr; <span className="hidden xs:inline">{t("paginationPrev")}</span><span className="xs:hidden">Prev</span>
              </button>

              {Array.from({ length: totalPages }, (_, i) => i + 1).map((pageNum) => (
                <button
                  key={pageNum}
                  type="button"
                  onClick={() => handlePageChange(pageNum)}
                  className={`w-7 h-7 xs:w-8 xs:h-8 rounded-lg xs:rounded-xl text-[11px] xs:text-xs font-black transition-all cursor-pointer shrink-0 min-h-[28px] xs:min-h-0 ${
                    currentPage === pageNum
                      ? "bg-[#0055B8] text-white shadow-md scale-105"
                      : "bg-[#F8FAFC] text-slate-700 hover:bg-slate-200 active:bg-slate-300 border border-slate-200"
                  }`}
                >
                  {pageNum}
                </button>
              ))}

              <button
                type="button"
                disabled={currentPage >= totalPages}
                onClick={() => handlePageChange(currentPage + 1)}
                className="px-2.5 xs:px-3 py-2 xs:py-1.5 bg-[#F1F5F9] hover:bg-slate-200 active:bg-slate-300 disabled:opacity-40 disabled:pointer-events-none text-slate-700 font-bold text-[11px] xs:text-xs rounded-lg xs:rounded-xl transition-all cursor-pointer shadow-2xs shrink-0 min-h-[36px] xs:min-h-0"
              >
                <span className="hidden xs:inline">{t("paginationNext")}</span><span className="xs:hidden">Next</span> &rarr;
              </button>
            </div>

            {/* Items Per Page Selector - Full width on xs, auto on sm */}
            <div className="flex items-center justify-center xs:justify-end gap-2 self-center sm:self-auto text-[11px] xs:text-xs order-3 w-full xs:w-auto">
              <span className="text-slate-400 font-medium whitespace-nowrap">{t("paginationPerPage")}</span>
              <div className="inline-flex p-0.5 bg-[#F1F5F9] rounded-lg xs:rounded-xl border border-slate-200">
                {[10, 12, 24].map((size) => (
                  <button
                    key={size}
                    type="button"
                    onClick={() => { setItemsPerPage(size); setCurrentPage(1); }}
                    className={`px-2.5 py-1 text-xs font-black rounded-lg transition-all cursor-pointer ${
                      itemsPerPage === size ? "bg-[#0055B8] text-white shadow-xs" : "text-slate-600 hover:text-black"
                    }`}
                  >
                    {size}
                  </button>
                ))}
              </div>
            </div>
          </div>

        </main>
      </div>

    </div>
  );
}

export default HomeClient;
