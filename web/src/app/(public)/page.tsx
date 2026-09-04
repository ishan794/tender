import { apiFetch } from "@/lib/api";
import { HomeClient } from "./home-client";
import type { TenderItem } from "@/data/tenders";
import { lkr } from "@/lib/format";

export const dynamic = "force-dynamic";

export function mapNoticeToTenderItem(n: any): TenderItem {
  const closingDate = n.closing_at ? new Date(n.closing_at.includes("T") ? n.closing_at : n.closing_at.replace(" ", "T") + "Z") : null;
  const now = new Date();
  const diffMs = closingDate ? closingDate.getTime() - now.getTime() : 0;
  const daysLeft = closingDate ? Math.max(0, Math.ceil(diffMs / (1000 * 60 * 60 * 24))) : 0;
  const val = Number(n.estimated_value) || 0;

  let valueBand = "<5M";
  if (val > 500_000_000) valueBand = ">500M";
  else if (val >= 100_000_000) valueBand = "100M-500M";
  else if (val >= 25_000_000) valueBand = "25M-100M";
  else if (val >= 5_000_000) valueBand = "5M-25M";
  else valueBand = "<5M";

  const district = n.district || "Colombo";
  const distSlug = (n.district_slug || district.toLowerCase().replace(/\s+/g, "-"));
  const provinceMap: Record<string, string> = {
    colombo: "western", gampaha: "western", kalutara: "western",
    kandy: "central", matale: "central", "nuwara-eliya": "central",
    galle: "southern", matara: "southern", hambantota: "southern",
    jaffna: "northern", kilinochchi: "northern", mannar: "northern", vavuniya: "northern", mullaitivu: "northern",
    batticaloa: "eastern", ampara: "eastern", trincomalee: "eastern",
    kurunegala: "north-western", puttalam: "north-western",
    anuradhapura: "north-central", polonnaruwa: "north-central",
    badulla: "uva", monaragala: "uva",
    ratnapura: "sabaragamuwa", kegalle: "sabaragamuwa",
  };
  const province = provinceMap[distSlug] || "western";

  const catSlug = (n.category_slug || "").toLowerCase();
  const catName = n.category || "General Procurement";

  let categoryId = "unclassified";
  const s = catSlug;
  const nm = catName.toLowerCase();
  if (s.includes("road") || s.includes("build") || s.includes("water") || s.includes("construct") || nm.includes("construction") || nm.includes("civil") || nm.includes("drainage")) {
    categoryId = "construction";
  } else if (s.includes("electric") || nm.includes("electrical")) {
    categoryId = "electrical";
  } else if (s.includes("vehicle") || s.includes("spare") || nm.includes("vehicle")) {
    categoryId = "vehicles";
  } else if (s.includes("med") || s.includes("pharma") || nm.includes("medical") || nm.includes("pharmaceutical")) {
    categoryId = "medical";
  } else if (s.includes("secur") || nm.includes("security")) {
    categoryId = "security";
  } else if (s.includes("food") || s.includes("ration") || nm.includes("food")) {
    categoryId = "food";
  } else if (s.includes("consult") || nm.includes("consult")) {
    categoryId = "consultancy";
  } else if (s.includes("hardw") || nm.includes("hardware") || s.includes("workstation") || nm.includes("desktop") || s.includes("it") || nm.includes("computer")) {
    categoryId = s.includes("it") || nm.includes("computer") ? "it" : "hardware";
  } else if (s.includes("clean") || s.includes("janitor") || nm.includes("janitorial")) {
    categoryId = "cleaning";
  } else if (s.includes("supplier") || nm.includes("supplier")) {
    categoryId = "suppliers";
  } else if (s.includes("solar") || nm.includes("solar")) {
    categoryId = "solar";
  } else if (s.includes("print") || nm.includes("print")) {
    categoryId = "printing";
  } else if (s.includes("transport") || nm.includes("transport")) {
    categoryId = "transport";
  } else if (s.includes("furniture") || nm.includes("furniture")) {
    categoryId = "furniture";
  } else if (s.includes("service") || nm.includes("service")) {
    categoryId = "services";
  } else {
    categoryId = s || "unclassified";
  }

  const entity = n.buyer || n.authority_name || n.org_name || (n.reference ? n.reference.split("/")[0] : "Procurement Entity");

  return {
    id: n.slug || String(n.id),
    ref: n.reference || `TH-${n.id}`,
    title: n.title || "Untitled Notice",
    entity: entity,
    province: province,
    district: district,
    location: `${district} District`,
    source: n.is_native ? "TenderHub e-Procurement" : "Official Gazette / Press",
    startDate: n.published_at ? new Date(n.published_at).toLocaleDateString("en-GB") : new Date().toLocaleDateString("en-GB"),
    endDate: closingDate ? closingDate.toLocaleDateString("en-GB") : "Closing Soon",
    daysLeft: daysLeft,
    contractType: n.kind === "auction" ? "Public Auction" : "National Competitive Bidding",
    instrumentType: n.kind === "auction" ? "Auction" : "Procurement",
    sector: n.sector || "government",
    categoryId: categoryId,
    categoryName: catName,
    valueBand: valueBand,
    amount: val > 0 ? lkr(val) : "Value on Application",
    amountNumeric: val,
    bidBond: val > 0 ? lkr(Math.round(val * 0.01)) : "As per Bidding Document",
    bidBondValidity: "90 Days from Submission",
    docFee: "Rs. 3,500 (Non-refundable)",
    cidaGrade: "Applicable / Per Document",
    preBidMeeting: "Refer Tender Dossier",
    openingTime: n.opening_at ? new Date(n.opening_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : "10:00 AM",
    contactPerson: n.contact_officer || "Head of Procurement Committee",
    contactPhone: n.contact_phone || "+94 11 200 0000",
    contactEmail: n.contact_email || "procurement@tenderhub.lk",
    submissionAddress: "Procurement Division, Colombo",
    deliveryPeriod: "Per Schedule of Requirements",
    paymentTerms: "30 Days from Inspection",
    isPromoted: false,
    isUrgent: daysLeft <= 3 && daysLeft > 0,
    hasDocuments: (n.documents_count || 0) > 0,
    docCount: n.documents_count || 0,
    description: n.summary_teaser || n.title,
    heroImage: "",
    technicalSpecs: [],
    documentsList: [],
  };
}

export default async function HomePage() {
  let notices: TenderItem[] = [];
  let stats = { live: 0, archived: 0, auctions: 0, added_today: 0, authorities: 0, awards: 0 };
  let facets = { category: [], district: [], sector: [], value_band: [] };
  let statusCounts = { all: 0, live: 0, closing_soon: 0, closed: 0 };

  try {
    const [noticesRes, statsRes] = await Promise.all([
      apiFetch<any[]>("/api/v1/notices?limit=1000"),
      apiFetch<any>("/api/v1/stats/summary"),
    ]);

    if (noticesRes.ok && noticesRes.body?.data) {
      const rows: any[] = Array.isArray(noticesRes.body.data) ? noticesRes.body.data : [];
      notices = rows.map(mapNoticeToTenderItem);
      facets = noticesRes.body.meta?.facets ?? facets;
      statusCounts = noticesRes.body.meta?.status_counts ?? statusCounts;
    }

    if (statsRes.ok && statsRes.body?.data) {
      stats = statsRes.body.data;
    }
  } catch (err) {
    console.error("[HomePage] Error fetching initial notices:", err);
  }

  return (
    <HomeClient
      initialNotices={notices}
      initialStats={stats}
      initialFacets={facets}
      initialStatusCounts={statusCounts}
    />
  );
}