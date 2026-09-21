import { useCallback, useEffect, useState } from "react";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersonelDetail";
import {
  PersonelDosyaHero,
  PersonelDosyaMissingInfoGateway,
  PersonelDosyaTabList,
  PersonelDosyaTabPanels,
  type PersonelDosyaTabId
} from "../components/personel-dosya";
import {
  personelTabQueryValue,
  resolvePersonelTab
} from "../components/personel-dosya/personel-dosya-tab-query";
import { usePersonelKartGatewayReturn } from "../hooks/usePersonelKartGatewayReturn";
import { getPersonelMissingFields } from "../personel-missing-info";

export function PersonelDetayPage() {
  const location = useLocation();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { personelId } = useParams();
  const parsedPersonelId = Number.parseInt(personelId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedPersonelId) && parsedPersonelId > 0;
  const { hasPermission, canWriteInSube } = useRoleAccess();
  const canCreateSurec = hasPermission("surecler.create");
  const canViewSurecler = hasPermission("surecler.view") || hasPermission("surecler.view.sube");
  const canAccessSurecler = canCreateSurec || canViewSurecler;
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewRevizyon = hasPermission("revizyon.view");
  const canViewFinans = hasPermission("finans.view");
  const canViewBordro = hasPermission("bordro_on_izleme.view");
  const canViewUcret = hasPermission("personeller.ucret.view");
  const canUpdatePersonel = hasPermission("personeller.update");
  const canViewBordroKapsam = hasPermission("personel_bordro_kapsam.view");
  const canManageAccountOnboarding = hasPermission("yonetim-paneli.manage");

  const initialTab = resolvePersonelTab(searchParams.get("tab")) ?? "genel-bilgiler";
  const [activeTab, setActiveTab] = useState<PersonelDosyaTabId>(initialTab);
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);

  const detail = usePersonelDetail(parsedPersonelId, hasValidId, {
    canViewSurecler,
    canCreateSurec,
    canCreateZimmet: false
  });

  const {
    personel,
    isLoading,
    errorMessage,
    refetch,
    surecHistory,
    surecHistoryHasMore,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    zimmetHistory,
    zimmetHistoryHasMore,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  } = detail;

  const isArchived = personel?.aktif_durum === "PASIF" || personel?.arsiv_modu === true;
  const isDisKaynak = personel?.calisan_kapsami === "DIS_KAYNAK";
  // Kayit, kullanicinin dogrudan islem yapabilecegi bir sirkette mi. Kapsam
  // disinda kart okunur kalir, yazma aksiyonlari acilmaz.
  const canWriteOnPersonel = canWriteInSube(personel?.sube_id ?? null);
  // Finansal sekmeler DIS için kapalı; operasyonel görünüm (puantaj bilgi) açılabilir.
  const isFinancialBlocked = isDisKaynak;
  const effectiveActiveTab = activeTab;
  const canCreateSurecEffective = Boolean(
    canCreateSurec && !isArchived && !isDisKaynak && canWriteOnPersonel
  );
  const canAccessSureclerEffective = Boolean(
    !isArchived && !isDisKaynak && (canCreateSurecEffective || canViewSurecler)
  );

  const { handleOpenSurecModal, handleOpenMissingInfo } = usePersonelKartGatewayReturn({
    navigate,
    parsedPersonelId
  });

  const syncTabInUrl = useCallback(
    (tabId: PersonelDosyaTabId, replace = true) => {
      const canonical = personelTabQueryValue(tabId);
      if (searchParams.get("tab") === canonical) {
        return;
      }
      const next = new URLSearchParams(searchParams);
      next.set("tab", canonical);
      setSearchParams(next, { replace });
    },
    [searchParams, setSearchParams]
  );

  useEffect(() => {
    const fromQuery = resolvePersonelTab(searchParams.get("tab"));
    const nextTab = fromQuery ?? "genel-bilgiler";
    setActiveTab(nextTab);
    setIsActionMenuOpen(false);
    if (!fromQuery && searchParams.get("tab")) {
      syncTabInUrl(nextTab);
    }
  }, [parsedPersonelId, searchParams, location.pathname, syncTabInUrl]);

  const handleTabChange = useCallback(
    (tabId: PersonelDosyaTabId) => {
      setActiveTab(tabId);
      syncTabInUrl(tabId);
    },
    [syncTabInUrl]
  );

  function handleOpenSurecHistory() {
    handleTabChange("surec-gecmisi");
  }

  const pageHeading =
    personel != null
      ? `${[personel.ad, personel.soyad].filter(Boolean).join(" ")} — Personel kartı detay alanı`
      : "Personel kartı detay alanı";

  const earliestReview =
    personel?.retention_summary?.earliest_destruction_review_date ??
    personel?.retention_summary?.retention_until ??
    null;

  const missingOnGenel = personel ? getPersonelMissingFields(personel).length : 0;

  return (
    <section className="personel-detay-page personel-dosya-page" aria-label={pageHeading}>
      <h2 className="personeller-sr-only">{pageHeading}</h2>

      {isLoading ? <LoadingState label="Personel kartı yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadı" message="Belirtilen ID ile kayıt bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          {isArchived ? (
            <div className="personel-archive-banner" data-testid="personel-arsiv-badge" role="status">
              <strong>Arşiv (salt okunur)</strong>
              <span> — Medisa saklama politikası</span>
              {personel.legal_hold_active ? <span> — Kayıt koruma altında</span> : null}
              {earliestReview ? (
                <span> — En erken imha değerlendirme tarihi: {earliestReview}</span>
              ) : null}
            </div>
          ) : null}

          <div className="personel-dosya-sticky-head" data-testid="personel-dosya-sticky-head">
            <PersonelDosyaHero personel={personel} />

            <div className="personel-dosya-tab-nav">
              <PersonelDosyaTabList
                activeTab={effectiveActiveTab}
                onTabChange={handleTabChange}
                directoryOnly={isDisKaynak}
                missingCounts={{ "genel-bilgiler": missingOnGenel }}
              />
            </div>
          </div>

          <PersonelDosyaMissingInfoGateway
            personel={personel}
            onOpenMissingInfo={
              canUpdatePersonel && !isArchived && canWriteOnPersonel ? handleOpenMissingInfo : undefined
            }
          />

          {!canWriteOnPersonel ? (
            <p className="personel-write-scope-notice" role="status" data-testid="personel-write-scope-notice">
              Bu işlem İK sorumlusu tarafından gerçekleştirilmelidir.
            </p>
          ) : null}

          <PersonelDosyaTabPanels
            activeTab={effectiveActiveTab}
            onTabChange={handleTabChange}
            personel={personel}
            surecler={surecHistory}
            surecHistoryHasMore={surecHistoryHasMore}
            zimmetler={zimmetHistory}
            zimmetHistoryHasMore={zimmetHistoryHasMore}
            isSurecHistoryLoading={isSurecHistoryLoading}
            surecHistoryErrorMessage={surecHistoryErrorMessage}
            isZimmetHistoryLoading={isZimmetHistoryLoading}
            zimmetHistoryErrorMessage={zimmetHistoryErrorMessage}
            canViewPuantaj={canViewPuantaj && !isArchived}
            canViewRevizyon={canViewRevizyon && !isArchived}
            canCreateRevizyon={false}
            canCreateZimmet={false}
            canAccessSurecler={canAccessSureclerEffective || (isArchived && canViewSurecler)}
            canViewFinans={canViewFinans && !isArchived && !isFinancialBlocked}
            canViewBordro={canViewBordro && !isArchived && !isFinancialBlocked}
            canViewUcret={canViewUcret && !isFinancialBlocked}
            canManageUcret={false}
            canViewBordroKapsam={canViewBordroKapsam && !isArchived && !isFinancialBlocked}
            canManageBordroKapsam={false}
            canApproveBordroKapsam={false}
            canManageAccountOnboarding={canManageAccountOnboarding && !isArchived}
            directoryOnly={isDisKaynak}
            genelActionRow={
              !isArchived && !isDisKaynak
                ? {
                    canAccessSurecler: canAccessSureclerEffective,
                    canCreateSurec: canCreateSurecEffective,
                    isActionMenuOpen,
                    onToggleActionMenu: () => setIsActionMenuOpen((prev) => !prev),
                    onCloseActionMenu: () => setIsActionMenuOpen(false),
                    onOpenSurecModal: handleOpenSurecModal,
                    onOpenSurecHistory: handleOpenSurecHistory
                  }
                : null
            }
          />
        </div>
      ) : null}
    </section>
  );
}
