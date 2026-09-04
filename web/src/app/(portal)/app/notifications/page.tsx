import { authed } from "@/lib/api";
import { PageHead } from "@/components/ds/app-shell";
import { NotificationsList } from "@/components/portal/notifications-list";

export const metadata = { title: "Notifications" };
export default async function NotificationsPage() {
  const res = await authed("/api/v1/account/notifications");
  return (
    <>
      <PageHead title="Notifications" sub="Your in-app notifications. Email, SMS and WhatsApp channels show their real delivery state." />
      <NotificationsList rows={res.body?.data ?? []} unread={res.body?.meta?.unread ?? 0} />
    </>
  );
}
