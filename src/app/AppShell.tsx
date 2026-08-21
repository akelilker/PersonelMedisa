import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Outlet, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { BackBar } from "../components/BackBar";
import { AppFooter } from "../components/footer/AppFooter";
import { Hero } from "../components/hero/Hero";
import type { KayitTab } from "../components/main-menu/MainMenu";
import { AppModal } from "../components/modal/AppModal";
import { ShellHeaderActions } from "../components/shell/ShellHeaderActions";
import { ShellModuleMenu } from "../components/shell/ShellModuleMenu";
import {
  KayitModalFooter,
  type KayitModalFooterModel
} from "../features/kayit/components/KayitModalFooter";
import { KayitSurecWorkspace } from "../features/kayit/components/KayitSurecWorkspace";
import { useKayitModalController } from "../features/kayit/hooks/useKayitModalController";
import { hasRolePermission } from "../lib/authorization/role-permissions";
import { formatUiProfileLabel, formatUserRoleLabel } from "../lib/display/enum-display";
import { resolveSecondaryModules } from "../lib/shell/secondary-module-nav";
import { useAuth } from "../state/auth.store";

export type AppShellOutletContext = {
  onKayitOpen: (tab: KayitTab) => void;
  /** Ana girişte kayıt modalı açıkken MainMenu gizlenir (önceki AppShell davranışı). */
  showMainMenu: boolean;
};

type ModuleModalConfig = {
  title: string;
  closeTo: string;
  backLabel?: string;
  backTestId?: string;
  className?: string;
  bodyClassName?: string;
  titleVariant?: "default" | "premium";
};

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

function resolveYonetimModalTitle(tabParam: string | null): string {
  const normalized = tabParam?.trim().toLowerCase() ?? "";
  if (normalized === "subeler" || normalized === "sube") {
    return "ŞUBE YÖNETİMİ";
  }
  if (normalized === "mevzuat") {
    return "MEVZUAT PARAMETRELERİ";
  }
  return "KULLANICI YÖNETİMİ";
}

function resolveModuleModal(pathname: string, tabParam: string | null): ModuleModalConfig | null {
  if (pathname === "/") {
    return null;
  }

  if (pathname === "/personeller/belge-takip") {
    return { title: "Belge Takip", closeTo: "/personeller", titleVariant: "premium" };
  }
  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { title: "Personel Kartı", closeTo: "/personeller", titleVariant: "premium" };
  }
  if (pathname === "/personeller") {
    return { title: "Personel Kartı", closeTo: "/", titleVariant: "premium" };
  }

  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { title: "Süreç Detayı", closeTo: "/surecler", titleVariant: "premium" };
  }
  if (pathname === "/surecler") {
    return { title: "Süreç Takibi", closeTo: "/", titleVariant: "premium" };
  }

  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { title: "Günlük Kayıt Detayı", closeTo: "/bildirimler", titleVariant: "premium" };
  }
  if (pathname === "/bildirimler") {
    return { title: "Günlük Kayıt Merkezi", closeTo: "/", titleVariant: "premium" };
  }

  if (pathname === "/raporlar") {
    return { title: "Raporlar", closeTo: "/", titleVariant: "premium" };
  }
  if (pathname === "/puantaj") {
    return { title: "Günlük Puantaj", closeTo: "/", titleVariant: "premium" };
  }
  if (pathname.startsWith("/haftalik-kapanis")) {
    return { title: "Haftalık Kapanış / Revizyon", closeTo: "/", titleVariant: "premium" };
  }
  if (pathname === "/finans") {
    return { title: "Finans", closeTo: "/", titleVariant: "premium" };
  }
  if (pathname === "/yonetim-paneli") {
    return {
      title: resolveYonetimModalTitle(tabParam),
      closeTo: "/",
      backLabel: "Ayarlar",
      backTestId: "yonetim-back-ayarlar",
      className: "modal-container--yonetim",
      bodyClassName: "modal-body--yonetim"
    };
  }
  if (pathname === "/resmi-tatil-takvimi") {
    return { title: "Resmî Tatil Takvimi", closeTo: "/", titleVariant: "premium" };
  }

  return { title: "Modül", closeTo: "/" };
}

function OverlayModulesMenu({ pathname }: { pathname: string }) {
  const wrapRef = useRef<HTMLDivElement | null>(null);
  const toggleRef = useRef<HTMLButtonElement | null>(null);
  const { session } = useAuth();
  const activeRole = session?.user.rol;
  const modules = useMemo(
    () => resolveSecondaryModules((permission) => hasRolePermission(activeRole, permission)),
    [activeRole]
  );
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    setIsOpen(false);
  }, [pathname]);

  useEffect(() => {
    function handleDocumentClick(event: MouseEvent) {
      const target = event.target as Node;
      if (wrapRef.current && !wrapRef.current.contains(target)) {
        setIsOpen(false);
      }
    }

    document.addEventListener("mousedown", handleDocumentClick);
    return () => {
      document.removeEventListener("mousedown", handleDocumentClick);
    };
  }, []);

  if (modules.length === 0) {
    return null;
  }

  return (
    <div className="modal-modules-bar" ref={wrapRef}>
      <ShellModuleMenu
        isOpen={isOpen}
        modules={modules}
        pathname={pathname}
        toggleRef={toggleRef}
        menuId="shell-overlay-modules-menu"
        toggleTestId="overlay-modules-toggle"
        navTestId="shell-overlay-modules-nav"
        linkTestIdPrefix="shell-overlay-module-link-"
        className="modules-menu-wrap--overlay"
        onToggle={() => setIsOpen((prev) => !prev)}
        onClose={() => setIsOpen(false)}
        onNavigate={() => setIsOpen(false)}
      />
    </div>
  );
}

export function AppShell() {
  const { session, logout } = useAuth();
  const navigate = useNavigate();
  const { pathname, state } = useLocation();
  const [searchParams] = useSearchParams();

  const isLoginRoute = pathname === "/login";
  const isHomeRoute = pathname === "/";
  const moduleModal = useMemo(
    () => (isLoginRoute ? null : resolveModuleModal(pathname, searchParams.get("tab"))),
    [isLoginRoute, pathname, searchParams]
  );
  const isModuleOverlayRoute = moduleModal !== null;
  const showShellHeaderActions = !isModuleOverlayRoute && !isLoginRoute;
  const showUserBar = !isLoginRoute && !isModuleOverlayRoute && !isHomeRoute;
  const backBarTarget = resolveBackBar(pathname);
  const activeSubeLabel = useMemo(() => {
    const activeSubeId = session?.active_sube_id;
    if (activeSubeId === null || activeSubeId === undefined) {
      return null;
    }

    return session?.sube_list?.find((sube) => sube.id === activeSubeId)?.ad ?? null;
  }, [session?.active_sube_id, session?.sube_list]);

  const {
    isKayitModalOpen,
    kayitTab,
    setKayitTab,
    kayitInitialSurecPersonelId,
    kayitInitialPersonelTab,
    kayitInitialOperation,
    kayitPrimaryLabel,
    kayitPrimaryFormId,
    openKayitModal,
    closeKayitModal
  } = useKayitModalController(pathname, state);

  const [kayitFooterModel, setKayitFooterModel] = useState<KayitModalFooterModel | null>(null);
  const handleKayitFooterModelChange = useCallback((model: KayitModalFooterModel | null) => {
    setKayitFooterModel(model);
  }, []);

  useEffect(() => {
    if (!isKayitModalOpen) {
      setKayitFooterModel(null);
    }
  }, [isKayitModalOpen]);

  useEffect(() => {
    document.body.classList.toggle("app-home-route", isHomeRoute && !isLoginRoute);

    return () => {
      document.body.classList.remove("app-home-route");
    };
  }, [isHomeRoute, isLoginRoute]);

  const outletContext = useMemo<AppShellOutletContext>(
    () => ({
      onKayitOpen: openKayitModal,
      showMainMenu: isHomeRoute ? !isKayitModalOpen : true
    }),
    [openKayitModal, isHomeRoute, isKayitModalOpen]
  );

  return (
    <div className="app-container app-shell">
      <main className="content-wrap">
        <div className="shell-top-stack">
          <Hero
            title="Personel Yönetim Sistemi"
            userLabel={session?.user.ad_soyad}
            subeLabel={activeSubeLabel}
          />
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

        {!isModuleOverlayRoute && backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
        {!isModuleOverlayRoute ? <Outlet context={outletContext} /> : null}
      </main>

      {isKayitModalOpen ? (
        <AppModal
          title="Kayıt ve Süreç İşlemleri"
          onClose={closeKayitModal}
          headerStart={
            <button type="button" className="modal-home-btn" onClick={closeKayitModal} aria-label="Ana sayfaya dön">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" />
              </svg>
            </button>
          }
          className="modal-container--kayit-surec"
          bodyClassName="modal-body--kayit-surec"
          titleVariant="premium"
          footerPlacement="flow"
          footer={kayitFooterModel ? <KayitModalFooter model={kayitFooterModel} /> : undefined}
        >
          <KayitSurecWorkspace
            activeTab={kayitTab}
            onTabChange={setKayitTab}
            onClose={closeKayitModal}
            initialSurecPersonelId={kayitInitialSurecPersonelId}
            initialPersonelTab={kayitInitialPersonelTab}
            initialOperation={kayitInitialOperation}
            primaryActionLabel={kayitPrimaryLabel}
            primaryFormId={kayitPrimaryFormId}
            onFooterModelChange={handleKayitFooterModelChange}
          />
        </AppModal>
      ) : null}

      {isModuleOverlayRoute && moduleModal ? (
        <AppModal
          title={moduleModal.title}
          onClose={() => navigate(moduleModal.closeTo)}
          backLabel={moduleModal.backLabel}
          onBack={moduleModal.backLabel ? () => navigate(moduleModal.closeTo) : undefined}
          backTestId={moduleModal.backTestId}
          className={moduleModal.className}
          bodyClassName={moduleModal.bodyClassName}
          titleVariant={moduleModal.titleVariant}
        >
          <OverlayModulesMenu pathname={pathname} />
          {backBarTarget ? <BackBar to={backBarTarget.to} label={backBarTarget.label} /> : null}
          <Outlet context={outletContext} />
        </AppModal>
      ) : null}

      <AppFooter />
    </div>
  );
}
