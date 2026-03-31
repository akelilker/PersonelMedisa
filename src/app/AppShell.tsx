import type { ReactNode } from "react";
import { Link } from "react-router-dom";
import { Hero } from "../components/hero/Hero";
import { AppFooter } from "../components/footer/AppFooter";
import { useRoleAccess } from "../hooks/use-role-access";
import { useAuth } from "../state/auth.store";

type AppShellProps = {
  children?: ReactNode;
};

export function AppShell({ children }: AppShellProps) {
  const { session, logout } = useAuth();
  const { hasPermission } = useRoleAccess();
  const canViewPersoneller = hasPermission("personeller.view");
  const canViewSurecler = hasPermission("surecler.view");
  const canViewBildirimler = hasPermission("bildirimler.view");
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewHaftalikKapanis = hasPermission("haftalik-kapanis.view");
  const canViewRaporlar = hasPermission("raporlar.view");
  const canViewFinans = hasPermission("finans.view");

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <Hero title="Personel ve Puantaj Yonetim Sistemi" />
        <div className="app-toolbar">
          <div className="app-toolbar-links">
            {canViewPersoneller ? <Link to="/personeller">Personeller</Link> : null}
            {canViewSurecler ? <Link to="/surecler">Surecler</Link> : null}
            {canViewBildirimler ? <Link to="/bildirimler">Bildirimler</Link> : null}
            {canViewPuantaj ? <Link to="/puantaj">Puantaj</Link> : null}
            {canViewHaftalikKapanis ? <Link to="/haftalik-kapanis">Haftalik Kapanis</Link> : null}
            {canViewRaporlar ? <Link to="/raporlar">Raporlar</Link> : null}
            {canViewFinans ? <Link to="/finans">Finans</Link> : null}
          </div>
          <div className="app-toolbar-user">
            <span>{session ? `${session.user.ad_soyad} (${session.user.rol})` : "-"}</span>
            <button type="button" onClick={logout}>
              Cikis
            </button>
          </div>
        </div>
        {children}
      </main>
      <AppFooter />
    </div>
  );
}
