export type Tier = "guest" | "free" | "paid";

export interface Notice {
  id: number;
  kind: "tender" | "auction";
  reference: string;
  slug: string;
  title: string;
  sector: string;
  category: string | null;
  category_slug?: string | null;
  district: string | null;
  district_slug?: string | null;
  estimated_value: number | null;
  currency: string;
  closing_at: string | null;
  opening_at: string | null;
  status: "live" | "closing_soon" | "closed" | string;
  documents_count: number;
  is_native: boolean;
  tier: Tier;
  locked: string[];
  summary_teaser?: string;
  summary?: string;
  description?: string;
  description_teaser?: string;
  buyer?: string | null;
  published_at?: string | null;
  contact_officer?: string | null;
  contact_phone?: string | null;
  contact_email?: string | null;
  source_url?: string | null;
  document_fee?: number | null;
  bid_security?: number | null;
  documents?: NoticeDocument[];
  matched_by?: string[];
  auction?: AuctionLot;
  locale?: string;
  is_fallback?: boolean;
  title_si?: string | null;
  title_ta?: string | null;
  summary_si?: string | null;
  summary_ta?: string | null;
  description_si?: string | null;
  description_ta?: string | null;
  translations?: {
    en?: { title?: string | null; summary?: string | null; description?: string | null; category?: string | null; district?: string | null; buyer?: string | null };
    si?: { title?: string | null; summary?: string | null; description?: string | null; category?: string | null; district?: string | null; buyer?: string | null };
    ta?: { title?: string | null; summary?: string | null; description?: string | null; category?: string | null; district?: string | null; buyer?: string | null };
  };
}

export interface NoticeDocument {
  id: number;
  name: string;
  kind: string;
  size_bytes: number;
  sha256: string | null;
  available: boolean;
  reason: string | null;
  source_url: string | null;
}

export interface AuctionLot {
  lot_no: string;
  asset_class: string;
  method: string;
  reserve: number | null;
  deposit_pct: number;
  deposit: number | null;
  venue: string | null;
  auctioneer: string | null;
  result: string | null;
  hammer_price: number | null;
  custody_note: string;
}

export interface FacetValue { slug: string; label: string; n: number }
export interface Facets {
  category: FacetValue[];
  district: FacetValue[];
  sector: FacetValue[];
  value_band: FacetValue[];
}

export interface Envelope<T> {
  data: T;
  meta: Record<string, any> & { now: string };
}

export interface Problem {
  status: number;
  reason: string;
  detail: string;
  upgrade_to?: string;
  [k: string]: any;
}

export interface Session {
  user: { id: number; name: string; email: string; role: string; group: string; free_views_used: number };
  org: { id: number; name: string; type: string; plan: string; sub_status: string; renews_at: string | null; verify_state: string };
}

export interface Submission {
  id: number;
  reference: string;
  size_bytes: number;
  status: string;
  received_at: string;
  bidder_name?: string;
  total_price?: number | string | null;
  has_security?: number;
  disqualified?: number;
}
