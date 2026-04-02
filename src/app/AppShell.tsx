import { useMemo, useState, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { BackBar } from "../components/BackBar";
import { Hero } from "../components/hero/Hero";
import { AppFooter } from "../components/footer/AppFooter";
import { AppModal } from "../components/modal/AppModal";
import { MainMenu, type KayitTab } from "../components/main-menu/MainMenu";
import { ShellHeaderActions } from "../components/shell/ShellHeaderActions";
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

function resolveModuleModal(pathname: string): { title: string; closeTo: string } | null {
  if (pathname === "/") {
    return null;
  }

  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { title: "Personel Detay", closeTo: "/personeller" };
  }
  if (pathname === "/personeller") {
    return { title: "Personel Karti", closeTo: "/" };
  }

  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { title: "Surec Detay", closeTo: "/surecler" };
  }
  if (pathname === "/surecler") {
    return { title: "Surec Takibi", closeTo: "/" };
  }

  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { title: "Bildirim Detay", closeTo: "/bildirimler" };
  }
  if (pathname === "/bildirimler") {
    return { title: "Bildirimler", closeTo: "/" };
  }

  if (pathname === "/raporlar") {
    return { title: "Raporlar", closeTo: "/" };
  }
  if (pathname === "/puantaj") {
    return { title: "Gunluk Puantaj", closeTo: "/" };
  }
  if (pathname === "/haftalik-kapanis") {
    return { title: "Haftalik Kapanis", closeTo: "/" };
  }
  if (pathname === "/finans") {
    return { title: "Finans", closeTo: "/" };
  }

  return { title: "Modul", closeTo: "/" };
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
  const showUserBar = !isHomeRoute && !isModuleOverlayRoute;
  const backBarTarget = resolveBackBar(pathname);
  const [isKayitModalOpen, setIsKayitModalOpen] = useState(false);
  const [kayitTab, setKayitTab] = useState<KayitTab>("yeni-kayit");
  const isAnyModalOpen = isKayitModalOpen;

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <Hero title="PERSONEL YONETIM SISTEMI" />

        {!moduleModal && !isLoginRoute ? <ShellHeaderActions /> : null}

        {showUserBar && !isLoginRoute ? (
          <div className="shell-user-bar">
            <div className="user-chip">
              <strong>{session?.user.ad_soyad ?? "-"}</strong>
              <span>
                ({session?.user.rol ?? "-"} -{" "}
                {session?.ui_profile === "birim_amiri" ? "Birim profili" : session ? "Yonetim profili" : "-"})
              </span>
            </div>
            <button type="button" className="logout-btn" onClick={logout}>
              Cikis
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
          title="Personel Giris ve Surec Takibi"
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
                  Yeni Kisi Ekle
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
                  Surec Ekranina Git
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
            </div>
          ) : null}

          {kayitTab === "surec" ? (
            <div className="kayit-tab-panel">
              <p>Surec olusturma, duzenleme ve takip islemleri bu sekmede yonetilir.</p>
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

