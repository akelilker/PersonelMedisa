import { useEffect, type ReactNode } from "react";
import { useLocation } from "react-router-dom";
import { BackBar } from "../components/BackBar";
import { Hero } from "../components/hero/Hero";
import { AppFooter } from "../components/footer/AppFooter";
import { MainMenu } from "../components/main-menu/MainMenu";
import { ShellHeaderActions } from "../components/shell/ShellHeaderActions";
import { formatUiProfileLabel, formatUserRoleLabel } from "../lib/display/enum-display";
import { useAuth } from "../state/auth.store";

type AppShellProps = {
  children?: ReactNode;
};

function resolveBackBar(pathname: string): { to: string; label: string } | null {
  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { to: "/personeller", label: "Personel listesine don" };
  }
  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { to: "/surecler", label: "Surec listesine don" };
  }
  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { to: "/bildirimler", label: "Bildirim listesine don" };
  }
  return null;
}

function resolveShellContextLabel(pathname: string): string {
  if (pathname === "/") {
    return "Ana panel";
  }
  if (pathname === "/personeller") {
    return "Personeller";
  }
  if (/^\/personeller\/\d+$/.test(pathname)) {
    return "Personel detayi";
  }
  if (pathname === "/surecler") {
    return "Surec takibi";
  }
  if (/^\/surecler\/\d+$/.test(pathname)) {
    return "Surec detayi";
  }
  if (pathname === "/bildirimler") {
    return "Bildirimler";
  }
  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return "Bildirim detayi";
  }
  if (pathname === "/puantaj") {
    return "Gunluk puantaj";
  }
  if (pathname === "/haftalik-kapanis") {
    return "Haftalik kapanis";
  }
  if (pathname === "/raporlar") {
    return "Raporlar";
  }
  if (pathname === "/finans") {
    return "Finans";
  }
  return "Modul";
}

export function AppShell({ children }: AppShellProps) {
  const { session, logout } = useAuth();
  const { pathname } = useLocation();
  const isLoginRoute = pathname === "/login";
  const isHomeRoute = pathname === "/";
  const backBarTarget = resolveBackBar(pathname);

  useEffect(() => {
    document.body.classList.toggle("dashboard-page", isHomeRoute && !isLoginRoute);

    return () => {
      document.body.classList.remove("dashboard-page");
    };
  }, [isHomeRoute, isLoginRoute]);

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <div className="shell-top-stack">
          <Hero title="Personel Yonetim Sistemi" />
          {!isLoginRoute ? <ShellHeaderActions contextLabel={resolveShellContextLabel(pathname)} /> : null}
        </div>

        {!isLoginRoute ? (
          <div className="shell-user-bar">
            <div className="user-chip">
              <strong>{session?.user.ad_soyad ?? "-"}</strong>
              <span>
                ({formatUserRoleLabel(session?.user.rol)} - {formatUiProfileLabel(session?.ui_profile)})
              </span>
            </div>
            <button type="button" className="logout-btn" onClick={logout}>
              Cikis
            </button>
          </div>
        ) : null}

        {!isLoginRoute ? <MainMenu variant={isHomeRoute ? "dashboard" : "compact"} /> : null}
        {!isLoginRoute && backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
        {children}
      </main>

      <AppFooter />
    </div>
  );
}
