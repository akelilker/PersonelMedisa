import { useMemo, useState, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { BackBar } from "../components/BackBar";
import { Hero } from "../components/hero/Hero";
import { AppFooter } from "../components/footer/AppFooter";
import { AppModal } from "../components/modal/AppModal";
import { MainMenu, type KayitTab } from "../components/main-menu/MainMenu";
import { ShellHeaderActions } from "../components/shell/ShellHeaderActions";
import { formatUiProfileLabel, formatUserRoleLabel } from "../lib/display/enum-display";
import { useAuth } from "../state/auth.store";

type AppShellProps = {
  children?: ReactNode;
};

function resolveBackBar(pathname: string): { to: string; label: string } | null {
  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { to: "/personeller", label: "Personel listesine dön" };
  }
  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { to: "/surecler", label: "Süreç listesine dön" };
  }
  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { to: "/bildirimler", label: "Bildirim listesine dön" };
  }
  return null;
}

function resolveModuleModal(pathname: string): { title: string; closeTo: string } | null {
  if (pathname === "/") {
    return null;
  }

  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { title: "Personel Detayı", closeTo: "/personeller" };
  }
  if (pathname === "/personeller") {
    return { title: "Personel Kartı", closeTo: "/" };
  }

  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { title: "Süreç Detayı", closeTo: "/surecler" };
  }
  if (pathname === "/surecler") {
    return { title: "Süreç Takibi", closeTo: "/" };
  }

  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { title: "Bildirim Detayı", closeTo: "/bildirimler" };
  }
  if (pathname === "/bildirimler") {
    return { title: "Bildirimler", closeTo: "/" };
  }

  if (pathname === "/raporlar") {
    return { title: "Raporlar", closeTo: "/" };
  }
  if (pathname === "/puantaj") {
    return { title: "Günlük Puantaj", closeTo: "/" };
  }
  if (pathname === "/haftalik-kapanis") {
    return { title: "Haftalık Kapanış", closeTo: "/" };
  }
  if (pathname === "/finans") {
    return { title: "Finans", closeTo: "/" };
  }

  return { title: "Modül", closeTo: "/" };
}

export function AppShell({ children }: AppShellProps) {
  const { session, logout } = useAuth();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const isLoginRoute = pathname === "/login";
  const isHomeRoute = pathname === "/";
  const moduleModal = useMemo(() => {
    if (pathname === "/login") {
      return null;
    }
    return resolveModuleModal(pathname);
  }, [pathname]);
  const isModuleOverlayRoute = moduleModal !== null;
  const showShellHeaderActions = !moduleModal && !isLoginRoute;
  const showUserBar = !isHomeRoute && !isModuleOverlayRoute;
  const backBarTarget = resolveBackBar(pathname);
  const [isKayitModalOpen, setIsKayitModalOpen] = useState(false);
  const [kayitTab, setKayitTab] = useState<KayitTab>("yeni-kayit");
  const isAnyModalOpen = isKayitModalOpen;

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <div className="shell-top-stack">
          <Hero title="Personel Yönetim Sistemi" />
          {showShellHeaderActions ? <ShellHeaderActions /> : null}
        </div>

        {showUserBar && !isLoginRoute ? (
          <div className="shell-user-bar">
            <div className="user-chip">
              <strong>{session?.user.ad_soyad ?? "-"}</strong>
              <span>
                ({formatUserRoleLabel(session?.user.rol)} - {formatUiProfileLabel(session?.ui_profile)})
              </span>
            </div>
            <button type="button" className="logout-btn" onClick={logout}>
              Çıkış
            </button>
          </div>
        ) : null}

        {isHomeRoute && !isLoginRoute && !isAnyModalOpen ? (
          <MainMenu
            onKayitOpen={(tab) => {
              setKayitTab(tab);
              setIsKayitModalOpen(true);
            }}
          />
        ) : null}

        {!isModuleOverlayRoute && backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
        {!isModuleOverlayRoute ? children : null}
      </main>

      {isKayitModalOpen ? (
        <AppModal
          title="Personel Giriş ve Süreç Takibi"
          onClose={() => {
            setIsKayitModalOpen(false);
          }}
          footer={
            kayitTab === "yeni-kayit" ? (
              <div className="universal-btn-group modal-footer-actions">
                <button
                  type="button"
                  className="universal-btn-save"
                  onClick={() => {
                    setIsKayitModalOpen(false);
                    navigate("/personeller", {
                      state: { openCreateModal: true }
                    });
                  }}
                >
                  Yeni Kişi Ekle
                </button>
                <button type="button" className="universal-btn-cancel" onClick={() => setIsKayitModalOpen(false)}>
                  Kapat
                </button>
              </div>
            ) : (
              <div className="universal-btn-group modal-footer-actions">
                <button
                  type="button"
                  className="universal-btn-save"
                  onClick={() => {
                    setIsKayitModalOpen(false);
                    navigate("/surecler");
                  }}
                >
                  Süreç Ekranına Git
                </button>
                <button type="button" className="universal-btn-cancel" onClick={() => setIsKayitModalOpen(false)}>
                  Kapat
                </button>
              </div>
            )
          }
        >
          <div className="kayit-tabs">
            <button
              type="button"
              className={`kayit-tab-btn${kayitTab === "yeni-kayit" ? " is-active" : ""}`}
              onClick={() => setKayitTab("yeni-kayit")}
            >
              Yeni Kayıt
            </button>
            <button
              type="button"
              className={`kayit-tab-btn${kayitTab === "surec" ? " is-active" : ""}`}
              onClick={() => setKayitTab("surec")}
            >
              Süreç
            </button>
          </div>

          {kayitTab === "yeni-kayit" ? (
            <div className="kayit-tab-panel">
              <p>Personel kartı açmak ve yeni personel kaydı için bu sekmeyi kullan.</p>
            </div>
          ) : null}

          {kayitTab === "surec" ? (
            <div className="kayit-tab-panel">
              <p>Süreç oluşturma, düzenleme ve takip işlemleri bu sekmede yönetilir.</p>
            </div>
          ) : null}
        </AppModal>
      ) : null}

      {isModuleOverlayRoute && moduleModal ? (
        <AppModal
          title={moduleModal.title}
          onClose={() => {
            navigate(moduleModal.closeTo);
          }}
        >
          {backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
          {children}
        </AppModal>
      ) : null}

      <AppFooter />
    </div>
  );
}

