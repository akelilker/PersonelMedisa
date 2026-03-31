import type { ReactNode } from "react";
import { NavLink } from "react-router-dom";
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
  const displayUser = session?.user.ad_soyad ?? "-";
  const displayRole = session?.user.rol ?? "-";

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <Hero title="PERSONEL YONETIM SISTEMI" />
        <div className="app-toolbar">
          <div className="app-toolbar-links">
            {canViewPersoneller ? (
              <NavLink to="/personeller" className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}>
                Personeller
              </NavLink>
            ) : null}
            {canViewSurecler ? (
              <NavLink to="/surecler" className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}>
                Surecler
              </NavLink>
            ) : null}
            {canViewBildirimler ? (
              <NavLink to="/bildirimler" className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}>
                Bildirimler
              </NavLink>
            ) : null}
            {canViewPuantaj ? (
              <NavLink to="/puantaj" className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}>
                Puantaj
              </NavLink>
            ) : null}
            {canViewHaftalikKapanis ? (
              <NavLink
                to="/haftalik-kapanis"
                className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}
              >
                Haftalik Kapanis
              </NavLink>
            ) : null}
            {canViewRaporlar ? (
              <NavLink to="/raporlar" className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}>
                Raporlar
              </NavLink>
            ) : null}
            {canViewFinans ? (
              <NavLink to="/finans" className={({ isActive }) => `menu-btn${isActive ? " is-active" : ""}`}>
                Finans
              </NavLink>
            ) : null}
          </div>

          <div className="app-toolbar-user">
            <div className="user-chip">
              <strong>{displayUser}</strong>
              <span>({displayRole})</span>
            </div>
            <button type="button" className="logout-btn" onClick={logout}>
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
