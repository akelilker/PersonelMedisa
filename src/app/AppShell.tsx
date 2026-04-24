import { useEffect, useMemo, useState, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { BackBar } from "../components/BackBar";
import { AppFooter } from "../components/footer/AppFooter";
import { Hero } from "../components/hero/Hero";
import { MainMenu, type KayitTab } from "../components/main-menu/MainMenu";
import { AppModal } from "../components/modal/AppModal";
import { ShellHeaderActions } from "../components/shell/ShellHeaderActions";
import {
  KAYIT_SUREC_PERSONEL_FORM_ID,
  KAYIT_SUREC_SUREC_FORM_ID,
  KayitSurecWorkspace
} from "../features/kayit/components/KayitSurecWorkspace";
import { formatUiProfileLabel, formatUserRoleLabel } from "../lib/display/enum-display";
import { useAuth } from "../state/auth.store";

type AppShellProps = {
  children?: ReactNode;
};

type ModuleModalConfig = {
  title: string;
  closeTo: string;
  className?: string;
  bodyClassName?: string;
};

type KayitModalIntent = "personel-edit-gateway";

type KayitModalRouteConfig = {
  tab: KayitTab;
  personelId: string | null;
  intent: KayitModalIntent | null;
  returnTo: string | null;
};

function resolveKayitModalRouteConfig(state: unknown): KayitModalRouteConfig | null {
  if (state === null || typeof state !== "object") {
    return null;
  }

  const kayitModal = (state as { kayitModal?: unknown }).kayitModal;
  if (kayitModal === null || typeof kayitModal !== "object") {
    return null;
  }

  const rawTab = (kayitModal as { tab?: unknown }).tab;
  const rawPersonelId = (kayitModal as { personelId?: unknown }).personelId;
  const rawIntent = (kayitModal as { intent?: unknown }).intent;
  const rawReturnTo = (kayitModal as { returnTo?: unknown }).returnTo;

  return {
    tab: rawTab === "surec" ? "surec" : "yeni-kayit",
    personelId: rawPersonelId === undefined || rawPersonelId === null ? null : String(rawPersonelId),
    intent: rawIntent === "personel-edit-gateway" ? rawIntent : null,
    returnTo: typeof rawReturnTo === "string" && rawReturnTo.trim() ? rawReturnTo.trim() : null
  };
}

function resolveBackBar(pathname: string): { to: string; label: string } | null {
  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { to: "/personeller", label: "Personel listesine dön" };
  }
  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { to: "/surecler", label: "Süreç listesine dön" };
  }
  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { to: "/bildirimler", label: "Günlük kayıt listesine dön" };
  }
  return null;
}

function resolveModuleModal(pathname: string): ModuleModalConfig | null {
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
    return { title: "Günlük Kayıt Detayı", closeTo: "/bildirimler" };
  }
  if (pathname === "/bildirimler") {
    return { title: "Günlük Kayıt Merkezi", closeTo: "/" };
  }

  if (pathname === "/raporlar") {
    return { title: "Raporlar", closeTo: "/" };
  }
  if (pathname === "/puantaj") {
    return { title: "Günlük Puantaj", closeTo: "/" };
  }
  if (pathname === "/finans") {
    return { title: "Finans", closeTo: "/" };
  }
  if (pathname === "/yonetim-paneli") {
    return {
      title: "Yönetim Paneli",
      closeTo: "/",
      className: "modal-container--yonetim",
      bodyClassName: "modal-body--yonetim"
    };
  }

  return { title: "Modül", closeTo: "/" };
}

export function AppShell({ children }: AppShellProps) {
  const { session, logout } = useAuth();
  const navigate = useNavigate();
  const { pathname, state } = useLocation();

  const isLoginRoute = pathname === "/login";
  const isHomeRoute = pathname === "/";
  const moduleModal = useMemo(() => (isLoginRoute ? null : resolveModuleModal(pathname)), [isLoginRoute, pathname]);
  const isModuleOverlayRoute = moduleModal !== null;
  const showShellHeaderActions = !isModuleOverlayRoute && !isLoginRoute;
  const showUserBar = !isLoginRoute && !isModuleOverlayRoute && !isHomeRoute;
  const backBarTarget = resolveBackBar(pathname);

  const [isKayitModalOpen, setIsKayitModalOpen] = useState(false);
  const [kayitTab, setKayitTab] = useState<KayitTab>("yeni-kayit");
  const [kayitInitialSurecPersonelId, setKayitInitialSurecPersonelId] = useState<string | null>(null);
  const [kayitEntryIntent, setKayitEntryIntent] = useState<KayitModalIntent | null>(null);
  const [kayitEntryReturnTo, setKayitEntryReturnTo] = useState<string | null>(null);
  const kayitRouteConfig = useMemo(() => resolveKayitModalRouteConfig(state), [state]);

  useEffect(() => {
    document.body.classList.toggle("dashboard-page", isHomeRoute && !isLoginRoute);

    return () => {
      document.body.classList.remove("dashboard-page");
    };
  }, [isHomeRoute, isLoginRoute]);

  useEffect(() => {
    if (!kayitRouteConfig) {
      return;
    }

    setKayitTab(kayitRouteConfig.tab);
    setKayitInitialSurecPersonelId(kayitRouteConfig.personelId);
    setKayitEntryIntent(kayitRouteConfig.intent);
    setKayitEntryReturnTo(kayitRouteConfig.returnTo);
    setIsKayitModalOpen(true);
    navigate(pathname, { replace: true, state: null });
  }, [kayitRouteConfig, navigate, pathname]);

  const kayitPrimaryLabel = kayitTab === "yeni-kayit" ? "Personeli Kaydet" : "Süreci Kaydet";
  const kayitPrimaryFormId =
    kayitTab === "yeni-kayit" ? KAYIT_SUREC_PERSONEL_FORM_ID : KAYIT_SUREC_SUREC_FORM_ID;

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <div className="shell-top-stack">
          <Hero title="Personel Yönetim Sistemi" />
          {showShellHeaderActions ? <ShellHeaderActions contextLabel="Ana panel" minimal={isHomeRoute} /> : null}
        </div>

        {showUserBar ? (
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

        {isHomeRoute && !isLoginRoute && !isKayitModalOpen ? (
          <MainMenu
            onKayitOpen={(tab) => {
              setKayitTab(tab);
              setKayitInitialSurecPersonelId(null);
              setKayitEntryIntent(null);
              setKayitEntryReturnTo(null);
              setIsKayitModalOpen(true);
            }}
          />
        ) : null}

        {!isModuleOverlayRoute && backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
        {!isModuleOverlayRoute ? children : null}
      </main>

      {isKayitModalOpen ? (
        <AppModal
          title="Kayıt ve Süreç İşlemleri"
          onClose={() => {
            setIsKayitModalOpen(false);
            setKayitInitialSurecPersonelId(null);
            setKayitEntryIntent(null);
            setKayitEntryReturnTo(null);
          }}
        >
          <KayitSurecWorkspace
            activeTab={kayitTab}
            onTabChange={setKayitTab}
            onClose={() => {
              setIsKayitModalOpen(false);
              setKayitInitialSurecPersonelId(null);
              setKayitEntryIntent(null);
              setKayitEntryReturnTo(null);
            }}
            initialSurecPersonelId={kayitInitialSurecPersonelId}
            initialIntent={kayitEntryIntent}
            initialReturnTo={kayitEntryReturnTo}
            primaryActionLabel={kayitPrimaryLabel}
            primaryFormId={kayitPrimaryFormId}
          />
        </AppModal>
      ) : null}

      {isModuleOverlayRoute && moduleModal ? (
        <AppModal
          title={moduleModal.title}
          onClose={() => navigate(moduleModal.closeTo)}
          className={moduleModal.className}
          bodyClassName={moduleModal.bodyClassName}
        >
          {backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
          {children}
        </AppModal>
      ) : null}

      <AppFooter />
    </div>
  );
}
