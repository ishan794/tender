"use client";
import { useState, useMemo, useEffect } from "react";
import { createPortal } from "react-dom";
import { useLanguage } from "@/context/LanguageContext";
import {
  PORTFOLIO_PROJECTS,
  PHOTO_CATEGORIES,
  VIDEO_CATEGORIES,
  type PhotoCategory,
  type VideoCategory,
  type PortfolioProject,
} from "@/data/portfolio";

type PanelType = "photo" | "video";

export default function PortfolioPage() {
  const { t, language } = useLanguage();
  const [activePanel, setActivePanel] = useState<PanelType>("photo");
  const [mounted, setMounted] = useState(false);

  const [selectedPhotoCategory, setSelectedPhotoCategory] = useState<PhotoCategory>("all");
  const [selectedVideoCategory, setSelectedVideoCategory] = useState<VideoCategory>("all");

  useEffect(() => {
    setMounted(true);
  }, []);

  const photoProjects = useMemo(() => {
    return PORTFOLIO_PROJECTS.filter((p) => p.type === "photo");
  }, []);

  const videoProjects = useMemo(() => {
    return PORTFOLIO_PROJECTS.filter((p) => p.type === "video");
  }, []);

  const filteredPhotoProjects = useMemo(() => {
    if (selectedPhotoCategory === "all") return photoProjects;
    return photoProjects.filter((p) => p.category === selectedPhotoCategory);
  }, [photoProjects, selectedPhotoCategory]);

  const filteredVideoProjects = useMemo(() => {
    if (selectedVideoCategory === "all") return videoProjects;
    return videoProjects.filter((p) => p.category === selectedVideoCategory);
  }, [videoProjects, selectedVideoCategory]);

  const getCategoryLabel = (category: PhotoCategory | VideoCategory, panel: PanelType): string => {
    const categories = panel === "photo" ? PHOTO_CATEGORIES : VIDEO_CATEGORIES;
    const found = categories.find((c) => c.id === category);
    return found?.label || category;
  };

  const getCategoryKey = (category: PhotoCategory | VideoCategory, panel: PanelType): string => {
    const prefix = panel === "photo" ? "photoCat" : "videoCat";
    return `${prefix}${category.charAt(0).toUpperCase() + category.slice(1).replace(/-/g, "")}`;
  };

  const renderMedia = (project: PortfolioProject) => {
    if (project.type === "photo") {
      return (
        <img
          src={project.mediaSrc}
          alt={project.title}
          className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
          loading="lazy"
        />
      );
    }
    return (
      <video
        src={project.mediaSrc}
        className="w-full h-full object-cover"
        muted
        loop
        playsInline
        preload="metadata"
        poster={project.thumbnailSrc}
      >
        <source src={project.mediaSrc} type="video/mp4" />
      </video>
    );
  };

  const renderThumbnail = (project: PortfolioProject) => {
    if (project.type === "photo") {
      return (
        <img
          src={project.thumbnailSrc || project.mediaSrc}
          alt={project.title}
          className="w-full h-full object-cover"
          loading="lazy"
        />
      );
    }
    return (
      <video
        src={project.mediaSrc}
        className="w-full h-full object-cover"
        muted
        loop
        playsInline
        preload="metadata"
        poster={project.thumbnailSrc}
      >
        <source src={project.mediaSrc} type="video/mp4" />
      </video>
    );
  };

  const PanelTabs = () => (
    <div className="flex items-center justify-center gap-2 mb-8 xs:mb-10 sm:mb-12">
      <button
        type="button"
        onClick={() => {
          setActivePanel("photo");
          setSelectedPhotoCategory("all");
        }}
        className={`px-6 xs:px-8 py-3 xs:py-3.5 rounded-2xl font-black text-sm xs:text-base uppercase tracking-wider transition-all cursor-pointer min-h-[48px] ${
          activePanel === "photo"
            ? "bg-[#0055B8] text-white shadow-lg shadow-[#0055B8]/30"
            : "bg-white text-slate-700 border border-slate-200 hover:border-[#0055B8] hover:text-[#0055B8] shadow-sm"
        }`}
      >
        {t("portfolioTabPhotos")}
        <span className="ml-2 px-2 py-0.5 text-xs font-mono bg-white/20 rounded-full">
          {photoProjects.length}
        </span>
      </button>
      <button
        type="button"
        onClick={() => {
          setActivePanel("video");
          setSelectedVideoCategory("all");
        }}
        className={`px-6 xs:px-8 py-3 xs:py-3.5 rounded-2xl font-black text-sm xs:text-base uppercase tracking-wider transition-all cursor-pointer min-h-[48px] ${
          activePanel === "video"
            ? "bg-[#0055B8] text-white shadow-lg shadow-[#0055B8]/30"
            : "bg-white text-slate-700 border border-slate-200 hover:border-[#0055B8] hover:text-[#0055B8] shadow-sm"
        }`}
      >
        {t("portfolioTabVideos")}
        <span className="ml-2 px-2 py-0.5 text-xs font-mono bg-white/20 rounded-full">
          {videoProjects.length}
        </span>
      </button>
    </div>
  );

  const CategoryFilter = ({
    categories,
    selectedCategory,
    onSelect,
    panel,
  }: {
    categories: { id: PhotoCategory | VideoCategory; label: string; count: number }[];
    selectedCategory: PhotoCategory | VideoCategory | "all";
    onSelect: (cat: PhotoCategory | VideoCategory | "all") => void;
    panel: PanelType;
  }) => {
    const handleSelect = (cat: PhotoCategory | VideoCategory | "all") => {
      onSelect(cat as PhotoCategory | VideoCategory | "all");
    };
    return (
    <div className="flex flex-wrap gap-2 xs:gap-3 mb-6 xs:mb-8 justify-center" role="group" aria-label={panel === "photo" ? t("photoCategoriesLabel") : t("videoCategoriesLabel")}>
      <button
        type="button"
        onClick={() => handleSelect("all")}
        className={`px-4 xs:px-5 py-2 xs:py-2.5 rounded-xl text-xs xs:text-sm font-bold transition-all cursor-pointer min-h-[40px] ${
          selectedCategory === "all"
            ? "bg-[#0055B8] text-white shadow-sm"
            : "bg-white text-slate-700 border border-slate-200 hover:border-[#0055B8] hover:text-[#0055B8] hover:bg-[#F8FAFC]"
        }`}
      >
        {t("allCategoriesOpt")}
        <span className="ml-1.5 px-1.5 py-0.5 text-[10px] font-mono bg-white/20 rounded-full">
          {panel === "photo" ? photoProjects.length : videoProjects.length}
        </span>
      </button>
      {categories.map((cat) => (
        <button
          key={cat.id}
          type="button"
          onClick={() => handleSelect(cat.id)}
          className={`px-4 xs:px-5 py-2 xs:py-2.5 rounded-xl text-xs xs:text-sm font-bold transition-all cursor-pointer min-h-[40px] ${
            selectedCategory === cat.id
              ? "bg-[#0055B8] text-white shadow-sm"
              : "bg-white text-slate-700 border border-slate-200 hover:border-[#0055B8] hover:text-[#0055B8] hover:bg-[#F8FAFC]"
          }`}
        >
          {cat.label}
          <span className="ml-1.5 px-1.5 py-0.5 text-[10px] font-mono bg-white/20 rounded-full">
            {cat.count}
          </span>
        </button>
      ))}
    </div>
  );
  };

  const ProjectGrid = ({ projects, panel }: { projects: PortfolioProject[]; panel: PanelType }) => {
    if (projects.length === 0) {
      return (
        <div className="col-span-full py-16 xs:py-24 text-center">
          <div className="w-16 h-16 xs:w-20 xs:h-20 mx-auto mb-4 rounded-full bg-slate-100 flex items-center justify-center">
            <svg className="w-8 h-8 xs:w-10 xs:h-10 text-slate-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
          <h3 className="text-lg xs:text-xl font-black text-slate-900 mb-2">{t("noProjectsFound")}</h3>
          <p className="text-slate-500 max-w-md mx-auto">{t("noProjectsDesc")}</p>
        </div>
      );
    }

    return (
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 xs:gap-5 lg:gap-6">
        {projects.map((project, index) => (
          <article
            key={project.id}
            className="group bg-white rounded-2xl overflow-hidden border border-slate-200/90 shadow-sm hover:shadow-xl transition-all duration-500 animate-fadeIn"
            style={{ animationDelay: `${index * 50}ms` }}
          >
            <div className="relative aspect-[4/3] xs:aspect-[3/4] overflow-hidden bg-slate-100">
              {renderMedia(project)}
              <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300" />
              <div className="absolute bottom-0 left-0 right-0 p-4 transform translate-y-full group-hover:translate-y-0 transition-transform duration-300">
                <span className="inline-block px-3 py-1.5 bg-white/90 backdrop-blur-sm rounded-full text-xs font-black text-slate-900 uppercase tracking-wider">
                  {project.type === "photo" ? t("photoLabel") : t("videoLabel")}
                </span>
              </div>
            </div>
            <div className="p-5 xs:p-6">
              <div className="flex items-center gap-2 mb-3">
                <span className="px-2.5 py-1 text-[10px] font-black rounded-full bg-[#EFF6FF] text-[#0055B8] uppercase tracking-wider">
                  {getCategoryLabel(project.category, panel)}
                </span>
                <span className="text-[11px] font-mono text-slate-400">{project.year}</span>
              </div>
              <h3 className="text-base xs:text-lg font-black text-slate-900 mb-1.5 line-clamp-1 group-hover:text-[#0055B8] transition-colors">
                {project.title}
              </h3>
              <p className="text-sm text-slate-600 font-medium">{project.client}</p>
              {project.description && (
                <p className="text-xs text-slate-500 mt-2 line-clamp-2">{project.description}</p>
              )}
            </div>
          </article>
        ))}
      </div>
    );
  };

  if (!mounted) {
    return (
      <div className="max-w-[1680px] 2xl:max-w-[1760px] mx-auto px-3 xs:px-4 sm:px-6 lg:px-8 2xl:px-10 py-6 xs:py-8 sm:py-10">
        <div className="animate-pulse space-y-8">
          <div className="h-8 bg-slate-200 rounded-xl w-1/4" />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {[...Array(8)].map((_, i) => (
              <div key={i} className="aspect-[3/4] bg-slate-200 rounded-2xl" />
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-[1680px] 2xl:max-w-[1760px] mx-auto px-3 xs:px-4 sm:px-6 lg:px-8 2xl:px-10 py-6 xs:py-8 sm:py-10">
      {/* Header Section */}
      <div className="mb-10 xs:mb-12 sm:mb-16 text-center">
        <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[#EFF6FF] text-[#0055B8] text-xs font-black uppercase tracking-widest mb-4 xs:mb-6">
          <span className="relative flex h-2 w-2">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#0055B8] opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2 w-2 bg-[#0055B8]"></span>
          </span>
          {t("portfolioBadge")}
        </span>
        <h1 className="font-display text-3xl xs:text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-black text-[#111827] uppercase leading-none mb-4 xs:mb-6 break-words">
          {t("portfolioTitle")}
        </h1>
        <p className="text-base xs:text-lg sm:text-xl text-[#6B7280] max-w-3xl mx-auto font-normal leading-relaxed">
          {t("portfolioDesc")}
        </p>
      </div>

      {/* Panel Tabs */}
      <PanelTabs />

      {/* Photo Panel */}
      {activePanel === "photo" && (
        <section id="photo-panel" className="animate-fadeIn" aria-labelledby="photo-heading">
          <h2 id="photo-heading" className="sr-only">
            {t("portfolioTabPhotos")}
          </h2>
          <CategoryFilter
            categories={PHOTO_CATEGORIES}
            selectedCategory={selectedPhotoCategory}
            onSelect={(cat) => setSelectedPhotoCategory(cat as PhotoCategory)}
            panel="photo"
          />
          <ProjectGrid projects={filteredPhotoProjects} panel="photo" />
        </section>
      )}

      {/* Video Panel */}
      {activePanel === "video" && (
        <section id="video-panel" className="animate-fadeIn" aria-labelledby="video-heading">
          <h2 id="video-heading" className="sr-only">
            {t("portfolioTabVideos")}
          </h2>
          <CategoryFilter
            categories={VIDEO_CATEGORIES}
            selectedCategory={selectedVideoCategory}
            onSelect={(cat) => setSelectedVideoCategory(cat as VideoCategory)}
            panel="video"
          />
          <ProjectGrid projects={filteredVideoProjects} panel="video" />
        </section>
      )}

      {/* Stats Footer */}
      <div className="mt-12 xs:mt-16 pt-8 xs:pt-10 border-t border-slate-200">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 xs:gap-6 text-center">
          <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-sm">
            <div className="font-display text-3xl xs:text-4xl font-black text-[#0055B8]">
              {photoProjects.length}
            </div>
            <div className="text-xs text-slate-500 uppercase tracking-wider font-extrabold mt-1">
              {t("portfolioStatPhotos")}
            </div>
          </div>
          <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-sm">
            <div className="font-display text-3xl xs:text-4xl font-black text-[#0055B8]">
              {videoProjects.length}
            </div>
            <div className="text-xs text-slate-500 uppercase tracking-wider font-extrabold mt-1">
              {t("portfolioStatVideos")}
            </div>
          </div>
          <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-sm">
            <div className="font-display text-3xl xs:text-4xl font-black text-[#0055B8]">
              {PHOTO_CATEGORIES.reduce((sum, c) => sum + c.count, 0) + VIDEO_CATEGORIES.reduce((sum, c) => sum + c.count, 0)}
            </div>
            <div className="text-xs text-slate-500 uppercase tracking-wider font-extrabold mt-1">
              {t("portfolioStatCategories")}
            </div>
          </div>
          <div className="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-sm">
            <div className="font-display text-3xl xs:text-4xl font-black text-[#0055B8]">
              {new Set(PORTFOLIO_PROJECTS.map((p) => p.client)).size}
            </div>
            <div className="text-xs text-slate-500 uppercase tracking-wider font-extrabold mt-1">
              {t("portfolioStatClients")}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}