import { redirect } from "next/navigation";
import { PortalShell } from "@/components/ds/app-shell";
import { readSession } from "@/lib/session";

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const s = await readSession();
  if (!s) redirect("/bidder/signin");

  return (
    <PortalShell
      portal="app"
      orgName={s.org.name}
      userName={s.user.name}
      plan={s.org.plan}
      nav={[
        { href: "/app", label: "Feed" },
        { href: "/app/alerts", label: "Alert profiles" },
        { href: "/app/pipeline", label: "Pipeline" },
        { href: "/app/vault", label: "Vault" },
        { href: "/app/notifications", label: "Notifications" },
        { href: "/app/privacy", label: "Privacy" },
      ]}
    >
      {children}
    </PortalShell>
  );
}
