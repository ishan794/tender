import { redirect } from "next/navigation";
import { PortalShell } from "@/components/ds/app-shell";
import { readSession } from "@/lib/session";

export default async function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  const s = await readSession();
  if (!s) redirect("/company/signin");

  return (
    <PortalShell
      portal="workspace"
      orgName={s.org.name}
      userName={`${s.user.name} · ${s.user.role}`}
      nav={[
        { href: "/workspace", label: "Tenders" },
        { href: "/workspace/planning", label: "Planning" },
        { href: "/workspace/contracts", label: "Contracts" },
        { href: "/workspace/calendar", label: "Calendar" },
        { href: "/workspace/analytics", label: "Analytics" },
        { href: "/workspace/auctions", label: "Auctions" },
        { href: "/workspace/suppliers", label: "Suppliers" },
        { href: "/workspace/team", label: "Team" },
      ]}
    >
      {children}
    </PortalShell>
  );
}
