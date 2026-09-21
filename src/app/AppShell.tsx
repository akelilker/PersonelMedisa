import { useCallback, useEffect, useMemo, useState } from "react";
import { Outlet, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { BackBar } from "../components/BackBar";
import { AppFooter } from "../components/footer/AppFooter";
import { Hero } from "../components/hero/Hero";
import type { KayitTab } from "../components/main-menu/MainMenu";
import { AppModal } from "../components/modal/AppModal";
import { ShellHeaderActions } from "../components/shell/ShellHeaderActions";
import {
  KayitModalFooter,
  type KayitModalFooterModel
} from "../features/kayit/components/KayitModalFooter";
import { KayitSurecWorkspace } from "../features/kayit/components/KayitSurecWorkspace";
import { useKayitModalController } from "../features/kayit/hooks/useKayitModalController";
import { formatUiProfileLabel, formatUserRoleLabel } from "../lib/display/enum-display";
import { resolveYonetimModalTitle } from "../lib/yonetim/yonetim-modal-title";
import { useAuth } from "../state/auth.store";
import { PersonelImportDryRunModal } from "../features/personeller/components/PersonelImportDryRunModal";
import { PersonelImportHistoryModal } from "../features/personeller/components/PersonelImportHistoryModal";
import { PersonelDetayPrintButton } from "../features/personeller/components/personel-dosya/PersonelDetayPrintButton";
import { readPersonelKartBack } from "../features/personeller/personel-kart-nav";
import { useRoleAccess } from "../hooks/use-role-access";

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

function resolveBackBar(pathname: string, state?: unknown): { to: string; label: string } | null {
  if (/^\/personeller\/\d+$/.test(pathname)) {
    const fromState = readPersonelKartBack(state);
    if (fromState) {
      return fromState;
    }
    return { to: "/personeller", label: "Personel Listesi" };
  }
  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { to: "/surecler", label: "Süreç listesine dön" };
  }
  if (/^\/bildirimler\/gunluk\/\d+$/.test(pathname)) {
    return { to: "/bildirimler", label: "Günlük kayıt listesine dön" };
  }
  if (/^\/bildirimler\/\d+$/.test(pathname)) {
    return { to: "/bildirimler", label: "Günlük kayıt listesine dön" };
  }
  return null;
}

function resolveModuleModal(pathname: string, tabParam: string | null): ModuleModalConfig | null {
  if (pathname === "/") {
    return null;
  }

  if (pathname === "/personeller/belge-takip") {
    return { title: "Belge Takip", closeTo: "/personeller", titleVariant: "premium" };
  }
  if (/^\/personeller\/\d+$/.test(pathname)) {
    return { title: "Personel Kartı", closeTo: "/", titleVariant: "premium" };
  }
  if (pathname === "/personeller") {
    return { title: "Personel Kartı", closeTo: "/", titleVariant: "premium" };
  }
  if (pathname === "/arsiv/personeller") {
    return { title: "Personel Kartı", closeTo: "/", titleVariant: "premium" };
  }

  if (/^\/surecler\/\d+$/.test(pathname)) {
    return { title: "Süreç Detayı", closeTo: "/surecler", titleVariant: "premium" };
  }
  if (pathname === "/surecler") {
    return { title: "Süreç Takibi", closeTo: "/", titleVariant: "premium" };
  }

  if (/^\/bildirimler\/gunluk\/\d+$/.test(pathname)) {
    return { title: "Devamsızlık Bildirimi", closeTo: "/bildirimler", titleVariant: "premium" };
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
      className: "modal-container--yonetim",
      bodyClassName: "modal-body--yonetim"
    };
  }
  if (pathname === "/resmi-tatil-takvimi") {
    return { title: "Resmî Tatil Takvimi", closeTo: "/", titleVariant: "premium" };
  }

  return { title: "Modül", closeTo: "/" };
}

function PersonelKartHomeButton({ onClick }: { onClick: () => void }) {
  return (
    <button type="button" className="modal-home-btn" onClick={onClick} aria-label="Ana sayfaya dön">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" />
      </svg>
    </button>
  );
}

export function AppShell() {
  const { session, logout } = useAuth();
  const navigate = useNavigate();
  const { pathname, state } = useLocation();
  const [searchParams] = useSearchParams();

  const isLoginRoute = pathname === "/login";
  const isHomeRoute = pathname === "/";
  const isYonetimRoute = pathname === "/yonetim-paneli";
  const moduleModal = useMemo(
    () => (isLoginRoute ? null : resolveModuleModal(pathname, searchParams.get("tab"))),
    [isLoginRoute, pathname, searchParams]
  );
  const isModuleOverlayRoute = moduleModal !== null;
  const showShellHeaderActions = !isModuleOverlayRoute && !isLoginRoute;
  const showUserBar = !isLoginRoute && !isModuleOverlayRoute && !isHomeRoute;
  const backBarTarget = resolveBackBar(pathname, state);
  const isPersonelKartModalRoute =
    pathname === "/personeller" ||
    pathname === "/arsiv/personeller" ||
    /^\/personeller\/\d+$/.test(pathname);
  const isPersonelDetayRoute = /^\/personeller\/\d+$/.test(pathname);
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
  const [bulkImportOpen, setBulkImportOpen] = useState(false);
  const [importHistoryOpen, setImportHistoryOpen] = useState(false);
  const { hasPermission } = useRoleAccess();
  const canApplyPersonelImport = hasPermission("personeller.import.apply");
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

      {bulkImportOpen ? (
        <PersonelImportDryRunModal
          open={bulkImportOpen}
          onClose={() => setBulkImportOpen(false)}
          onHome={() => {
            setBulkImportOpen(false);
            closeKayitModal();
          }}
          canApply={canApplyPersonelImport}
          onOpenImportHistory={canApplyPersonelImport ? () => setImportHistoryOpen(true) : undefined}
        />
      ) : null}

      {importHistoryOpen ? (
        <PersonelImportHistoryModal open={importHistoryOpen} onClose={() => setImportHistoryOpen(false)} />
      ) : null}

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
            onOpenBulkImport={canApplyPersonelImport ? () => setBulkImportOpen(true) : undefined}
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
          headerStart={
            isPersonelKartModalRoute ? (
              <PersonelKartHomeButton onClick={() => navigate("/")} />
            ) : undefined
          }
          className={moduleModal.className}
          bodyClassName={moduleModal.bodyClassName}
          titleVariant={moduleModal.titleVariant}
        >
          {isYonetimRoute ? (
            <BackBar to="/" label="Ayarlar" testId="yonetim-back-ayarlar" />
          ) : null}
          {backBarTarget ? (
            <BackBar
              to={backBarTarget.to}
              label={backBarTarget.label}
              endContent={isPersonelDetayRoute ? <PersonelDetayPrintButton /> : undefined}
            />
          ) : null}
          <Outlet context={outletContext} />
        </AppModal>
      ) : null}

      <AppFooter loginFooter={isLoginRoute} />
    </div>
  );
}
