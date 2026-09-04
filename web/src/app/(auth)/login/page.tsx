"use client";
import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useToast } from "@/components/ui/Toaster";
import { useLanguage } from "@/context/LanguageContext";

export default function LoginPage() {
  const router = useRouter();
  const toast = useToast();
  const { t } = useLanguage();

  const [isResetMode, setIsResetMode] = useState(false);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [resetEmail, setResetEmail] = useState("");
  const [resetSent, setResetSent] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  /**
   * Real authentication. This used to set `tenderhub_auth=authenticated` from
   * client-side JavaScript and redirect — no server was ever contacted, and
   * because the cookie was written by JS it was not httpOnly, so any visitor
   * could set it in devtools and reach the dashboard. The credentials now go
   * to the BFF, which forwards them to CodeIgniter and returns httpOnly
   * cookies only if the password actually verified.
   */
  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!email.trim() || !email.includes("@") || !email.includes(".")) {
      toast.error("Invalid Credentials", "Please enter a valid authorized supplier email address.");
      return;
    }
    if (!password) {
      toast.error("Invalid Credentials", "Please enter your password.");
      return;
    }

    setIsLoading(true);
    try {
      const res = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: email.trim(), password }),
      });
      const json = await res.json().catch(() => null);

      if (!res.ok) {
        setIsLoading(false);
        toast.error(
          res.status === 429 ? "Too Many Attempts" : "Sign In Failed",
          json?.detail ?? "E-mail or password is incorrect.",
        );
        return;
      }

      toast.success("Authorization Verified", "Welcome back. Opening your portal…");

      // Land people where their account actually belongs.
      const group = json?.data?.user?.group;
      const home = group === "staff" ? "/console" : group === "company" ? "/workspace" : "/app";
      router.push(home);
      router.refresh();
    } catch {
      setIsLoading(false);
      toast.error("Service Unavailable", "Sign-in is temporarily unavailable. Please try again shortly.");
    }
  };

  /**
   * Password recovery actually calls the backend. Previously this only set
   * local state and told the user a link had been dispatched — nothing was
   * ever sent. The success message is now shown only when the server accepted
   * the request. The response is deliberately identical whether or not the
   * address is registered, so it cannot be used to enumerate accounts.
   */
  const handleResetSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resetEmail.trim() || !resetEmail.includes("@") || !resetEmail.includes(".")) {
      toast.error("Invalid Email Format", "Please provide a valid registered corporate email address.");
      return;
    }

    setIsLoading(true);
    try {
      const res = await fetch("/api/auth/forgot-password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: resetEmail.trim() }),
      });
      const json = await res.json().catch(() => null);
      setIsLoading(false);

      if (!res.ok) {
        toast.error(
          res.status === 429 ? "Too Many Attempts" : "Recovery Unavailable",
          json?.detail ?? "We could not process that request. Please try again shortly.",
        );
        return;
      }

      setResetSent(true);
      toast.success("Recovery Dispatched", "If that address has an account, a reset link is on its way.");
    } catch {
      setIsLoading(false);
      toast.error("Service Unavailable", "Password recovery is temporarily unavailable. Please try again shortly.");
    }
  };

  return (
    <div className="max-w-[460px] mx-auto px-6 py-16">
      <div className="bg-white border border-slate-200/90 p-8 lg:p-10 rounded-2xl shadow-md">
        
        {!isResetMode ? (
          /* 1. Normal Sign In View */
          <div className="animate-fadeIn">
            <h1 className="font-display text-3xl sm:text-4xl font-black text-[#0F172A] uppercase mb-2 text-center tracking-tight">
              {t("loginTitle")}
            </h1>
            <p className="text-xs text-slate-500 font-normal text-center mb-8">
              {t("loginSubtitle")}
            </p>

            <form onSubmit={handleLoginSubmit} className="flex flex-col gap-4">
              <div className="flex flex-col gap-1.5">
                <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                  {t("loginEmailLabel")}
                </label>
                <input 
                  type="email" 
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder={t("loginEmailPlaceholder")} 
                  className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all placeholder:text-slate-400 placeholder:font-normal"
                />
              </div>

              <div className="flex flex-col gap-1.5">
                <div className="flex justify-between items-center">
                  <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                    {t("loginPasswordLabel")}
                  </label>
                  <button
                    type="button"
                    onClick={() => {
                      setIsResetMode(true);
                      setResetSent(false);
                    }}
                    className="text-xs text-[#0055B8] font-bold hover:underline cursor-pointer"
                  >
                    {t("loginForgot")}
                  </button>
                </div>
                <input 
                  type="password" 
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••" 
                  className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all placeholder:text-slate-400 placeholder:font-normal"
                />
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="w-full bg-[#0055B8] hover:bg-[#004394] disabled:opacity-50 text-white font-extrabold text-xs sm:text-sm py-3.5 rounded-xl transition-all hover:-translate-y-0.5 active:scale-95 shadow-md mt-2 uppercase tracking-wider cursor-pointer"
              >
                {isLoading ? t("loginAuthenticating") : t("loginSignIn")}
              </button>
            </form>

            <div className="text-center text-xs text-slate-500 font-normal mt-8 pt-6 border-t border-slate-200">
              {t("loginNewContractor")}{" "}
              <Link href="/register" className="text-[#0055B8] font-bold hover:underline">
                {t("loginRegisterFree")}
              </Link>
            </div>
          </div>
        ) : (
          /* 2. Interactive Forgot Password / Recovery View */
          <div className="animate-fadeIn">
            <h1 className="font-display text-3xl sm:text-4xl font-black text-[#0F172A] uppercase mb-2 text-center tracking-tight">
              {t("loginResetTitle")}
            </h1>
            <p className="text-xs text-slate-500 font-normal text-center mb-8">
              {t("loginResetSubtitle")}
            </p>

            {resetSent ? (
              <div className="bg-[#EFF6FF] border border-[#BFDBFE] rounded-2xl p-6 text-center animate-fadeIn">
                <div className="text-[#0055B8] font-black text-base mb-2">
                  {t("loginRecoveryDispatched")}
                </div>
                <p className="text-xs text-slate-700 font-normal leading-relaxed mb-6">
                  {t("loginRecoveryDesc")} <strong className="text-[#0055B8] font-bold">{resetEmail}</strong>. {t("loginCheckInbox")}
                </p>
                <button
                  type="button"
                  onClick={() => {
                    setIsResetMode(false);
                    setResetSent(false);
                    setResetEmail("");
                  }}
                  className="w-full bg-[#0055B8] hover:bg-[#004394] text-white font-extrabold text-xs py-3 rounded-xl transition-all hover:-translate-y-0.5 active:scale-95 uppercase tracking-wider cursor-pointer"
                >
                  {t("loginReturnSignIn")}
                </button>
              </div>
            ) : (
              <form onSubmit={handleResetSubmit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                  <label className="text-[11px] font-black uppercase tracking-wider text-slate-500">
                    {t("loginCorporateEmail")}
                  </label>
                  <input 
                    type="email" 
                    required
                    value={resetEmail}
                    onChange={(e) => setResetEmail(e.target.value)}
                    placeholder={t("loginEmailPlaceholder")} 
                    className="w-full bg-[#F8FAFC] border border-slate-200 focus:border-[#0055B8] focus:bg-white rounded-xl py-3 px-4 text-xs sm:text-sm font-semibold text-slate-900 outline-none transition-all placeholder:text-slate-400 placeholder:font-normal"
                  />
                </div>

                <button
                  type="submit"
                  className="w-full bg-[#0055B8] hover:bg-[#004394] text-white font-extrabold text-xs sm:text-sm py-3.5 rounded-xl transition-all hover:-translate-y-0.5 active:scale-95 shadow-md mt-2 uppercase tracking-wider cursor-pointer"
                >
                  {t("loginSendRecovery")}
                </button>

                <button
                  type="button"
                  onClick={() => setIsResetMode(false)}
                  className="text-xs font-bold text-slate-500 hover:text-[#0055B8] text-center pt-2 transition-colors cursor-pointer"
                >
                  {t("loginBackToSignIn")}
                </button>
              </form>
            )}
          </div>
        )}

      </div>
    </div>
  );
}
