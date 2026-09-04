import { PageHead } from "@/components/ds/app-shell";
import { PrivacyPanel } from "@/components/portal/privacy-panel";

export const metadata = { title: "Privacy" };
export default async function PrivacyPage() {
  return (
    <>
      <PageHead title="Privacy" sub="Your data rights under the PDPA: see and export the data we hold about you, or request correction or deletion." />
      <PrivacyPanel />
    </>
  );
}
