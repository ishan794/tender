import Navbar from "@/components/layout/Navbar";
import Footer from "@/components/layout/Footer";

/**
 * Marketing chrome. Scoped to the public route group so it stops appearing
 * inside the portals — the console, workspace and bidder app each render their
 * own PortalShell and previously got this header and footer stacked around it.
 */
export default function PublicLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <Navbar />
      <main className="flex-grow">{children}</main>
      <Footer />
    </>
  );
}
