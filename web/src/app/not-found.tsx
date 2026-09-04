import Link from "next/link";

export const metadata = { title: "Page not found — TenderHub" };

export default function NotFound() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center px-6 text-center bg-[#F8FAFC]">
      <span className="font-display font-black text-2xl tracking-tight text-[#0F172A] mb-6">
        TENDER<span className="text-[#0055B8]">HUB</span>
      </span>
      <div className="bg-white border border-slate-200/90 rounded-2xl shadow-md p-8 lg:p-10 max-w-[440px] w-full">
        <div className="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-full bg-[#EFF6FF] text-[#0055B8] text-xl font-black">
          404
        </div>
        <h1 className="font-display text-2xl sm:text-3xl font-black uppercase tracking-tight text-[#0F172A] mb-2">
          Page not found
        </h1>
        <p className="text-sm text-slate-600 leading-relaxed mb-7">
          The page you were looking for does not exist or may have been moved. The tender
          you want might have closed and been archived.
        </p>
        <div className="flex flex-col gap-3">
          <Link
            href="/"
            className="w-full bg-[#0055B8] hover:bg-[#004394] text-white font-extrabold text-xs py-3 rounded-xl transition-all uppercase tracking-wider"
          >
            Return home
          </Link>
          <Link
            href="/tenders-sri-lanka"
            className="text-xs font-bold text-slate-500 hover:text-[#0055B8] transition-colors"
          >
            Browse live tenders
          </Link>
        </div>
      </div>
    </div>
  );
}
