import { useState, type ReactNode } from "react";
import { NavLink, useLocation, useNavigate } from "react-router-dom";
import { Hero } from "../components/hero/Hero";
import { AppFooter } from "../components/footer/AppFooter";
import { AppModal } from "../components/modal/AppModal";
import { useRoleAccess } from "../hooks/use-role-access";
import { useAuth } from "../state/auth.store";

type AppShellProps = {
  children?: ReactNode;
};

type KayitTab = "yeni-kayit" | "surec";

export function AppShell({ children }: AppShellProps) {
  const { session, logout } = useAuth();
  const { hasPermission } = useRoleAccess();
  const navigate = useNavigate();
  const location = useLocation();
  const [isKayitModalOpen, setIsKayitModalOpen] = useState(false);
  const [kayitTab, setKayitTab] = useState<KayitTab>("yeni-kayit");

  const canViewPersoneller = hasPermission("personeller.view");
  const canViewSurecler = hasPermission("surecler.view");
  const canViewBildirimler = hasPermission("bildirimler.view");
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewHaftalikKapanis = hasPermission("haftalik-kapanis.view");
  const canViewRaporlar = hasPermission("raporlar.view");
  const canViewFinans = hasPermission("finans.view");

  const hasOperasyonSection = canViewBildirimler || canViewPuantaj || canViewHaftalikKapanis;
  const hasRaporSection = canViewRaporlar || canViewFinans;
  const operasyonTarget = canViewBildirimler
    ? "/bildirimler"
    : canViewPuantaj
    ? "/puantaj"
    : "/haftalik-kapanis";
  const raporTarget = canViewRaporlar ? "/raporlar" : "/finans";
  const isKayitSectionActive =
    location.pathname.startsWith("/personeller") || location.pathname.startsWith("/surecler");
  const isOperasyonSectionActive =
    location.pathname.startsWith("/bildirimler") ||
    location.pathname.startsWith("/puantaj") ||
    location.pathname.startsWith("/haftalik-kapanis");
  const isRaporSectionActive =
    location.pathname.startsWith("/raporlar") || location.pathname.startsWith("/finans");

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <Hero title="PERSONEL YONETIM SISTEMI" />

        <div className="app-toolbar">
          <div className="app-toolbar-links">
            <button
              type="button"
              className={`menu-btn${isKayitSectionActive ? " is-active" : ""}`}
              onClick={() => {
                setKayitTab(isKayitSectionActive && location.pathname.startsWith("/surecler") ? "surec" : "yeni-kayit");
                setIsKayitModalOpen(true);
              }}
            >
              Kayit Islemleri
            </button>

            {hasOperasyonSection ? (
              <button
                type="button"
                className={`menu-btn${isOperasyonSectionActive ? " is-active" : ""}`}
                onClick={() => navigate(operasyonTarget)}
              >
                Operasyon
              </button>
            ) : null}

            {hasRaporSection ? (
              <button
                type="button"
                className={`menu-btn${isRaporSectionActive ? " is-active" : ""}`}
                onClick={() => navigate(raporTarget)}
              >
                Rapor ve Finans
              </button>
            ) : null}
          </div>

          <div className="app-toolbar-user">
            <div className="user-chip">
              <strong>{session?.user.ad_soyad ?? "-"}</strong>
              <span>({session?.user.rol ?? "-"})</span>
            </div>
            <button type="button" className="logout-btn" onClick={logout}>
              Cikis
            </button>
          </div>

          <div className="quick-links">
            {canViewPersoneller ? (
              <NavLink to="/personeller" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Personeller
              </NavLink>
            ) : null}
            {canViewSurecler ? (
              <NavLink to="/surecler" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Surecler
              </NavLink>
            ) : null}
            {canViewBildirimler ? (
              <NavLink to="/bildirimler" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Bildirimler
              </NavLink>
            ) : null}
            {canViewPuantaj ? (
              <NavLink to="/puantaj" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Puantaj
              </NavLink>
            ) : null}
            {canViewHaftalikKapanis ? (
              <NavLink to="/haftalik-kapanis" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Haftalik Kapanis
              </NavLink>
            ) : null}
            {canViewRaporlar ? (
              <NavLink to="/raporlar" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Raporlar
              </NavLink>
            ) : null}
            {canViewFinans ? (
              <NavLink to="/finans" className={({ isActive }) => (isActive ? "active" : undefined)}>
                Finans
              </NavLink>
            ) : null}
          </div>
        </div>

        {children}
      </main>

      {isKayitModalOpen ? (
        <AppModal
          title="KAYIT ISLEMLERI"
          onClose={() => {
            setIsKayitModalOpen(false);
          }}
        >
          <div className="kayit-tabs">
            <button
              type="button"
              className={`kayit-tab-btn${kayitTab === "yeni-kayit" ? " is-active" : ""}`}
              onClick={() => setKayitTab("yeni-kayit")}
            >
              Yeni Kayit
            </button>
            <button
              type="button"
              className={`kayit-tab-btn${kayitTab === "surec" ? " is-active" : ""}`}
              onClick={() => setKayitTab("surec")}
            >
              Surec
            </button>
          </div>

          {kayitTab === "yeni-kayit" ? (
            <div className="kayit-tab-panel">
              <p>Personel karti acmak ve yeni personel kaydi icin bu sekmeyi kullan.</p>
              <button
                type="button"
                className="state-action-btn"
                onClick={() => {
                  setIsKayitModalOpen(false);
                  navigate("/personeller");
                }}
              >
                Yeni Kayit Ekranina Git
              </button>
            </div>
          ) : null}

          {kayitTab === "surec" ? (
            <div className="kayit-tab-panel">
              <p>Surec olusturma, duzenleme ve takip islemleri bu sekmede yonetilir.</p>
              <button
                type="button"
                className="state-action-btn"
                onClick={() => {
                  setIsKayitModalOpen(false);
                  navigate("/surecler");
                }}
              >
                Surec Ekranina Git
              </button>
            </div>
          ) : null}
        </AppModal>
      ) : null}

      <AppFooter />
    </div>
  );
}
