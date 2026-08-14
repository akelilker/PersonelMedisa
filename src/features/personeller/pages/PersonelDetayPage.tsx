import { useEffect, useState } from "react";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersonelDetail";
import {
  PERSONEL_DOSYA_TABS,
  PersonelDosyaActionRow,
  PersonelDosyaHero,
  PersonelDosyaTabPanels,
  type PersonelDosyaTabId
} from "../components/personel-dosya";
import { usePersonelKartGatewayReturn } from "../hooks/usePersonelKartGatewayReturn";

function resolvePersonelTab(raw: string | null): PersonelDosyaTabId | null {
  if (!raw) return null;
  if (raw === "genel" || raw === "ucret") return "genel-bilgiler";
  const match = PERSONEL_DOSYA_TABS.find((tab) => tab.id === raw);
  return match ? match.id : null;
}

export function PersonelDetayPage() {
  const location = useLocation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { personelId } = useParams();
  const parsedPersonelId = Number.parseInt(personelId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedPersonelId) && parsedPersonelId > 0;
  const { hasPermission } = useRoleAccess();
  const canCreateSurec = hasPermission("surecler.create");
  const canViewSurecler = hasPermission("surecler.view") || hasPermission("surecler.view.sube");
  const canAccessSurecler = canCreateSurec || canViewSurecler;
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewRevizyon = hasPermission("revizyon.view");
  const canViewFinans = hasPermission("finans.view");
  const canViewBordro = hasPermission("bordro_on_izleme.view");
  const canViewUcret = hasPermission("personeller.ucret.view");
  const canViewBordroKapsam = hasPermission("personel_bordro_kapsam.view");

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
  const isDirectoryOnly = personel?.calisan_kapsami === "DIS_KAYNAK";
  const effectiveActiveTab = isDirectoryOnly && activeTab !== "genel-bilgiler" && activeTab !== "egitim-belgeler"
    ? "genel-bilgiler"
    : activeTab;
  const canCreateSurecEffective = Boolean(canCreateSurec && !isArchived && !isDirectoryOnly);
  const canAccessSureclerEffective = Boolean(
    !isArchived && !isDirectoryOnly && (canCreateSurecEffective || canViewSurecler)
  );

  const { handleOpenSurecModal } = usePersonelKartGatewayReturn({
    navigate,
    parsedPersonelId
  });

  useEffect(() => {
    const fromQuery = resolvePersonelTab(searchParams.get("tab"));
    setActiveTab(fromQuery ?? "genel-bilgiler");
    setIsActionMenuOpen(false);
  }, [parsedPersonelId, searchParams, location.pathname]);

  function handleOpenSurecHistory() {
    setActiveTab("surec-gecmisi");
  }

  const pageHeading =
    personel != null
      ? `${[personel.ad, personel.soyad].filter(Boolean).join(" ")} — Personel kartı detay alanı`
      : "Personel kartı detay alanı";

  const earliestReview =
    personel?.retention_summary?.earliest_destruction_review_date ??
    personel?.retention_summary?.retention_until ??
    null;

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
              {personel.legal_hold_active ? <span> — Legal hold aktif</span> : null}
              {earliestReview ? (
                <span> — En erken imha değerlendirme tarihi: {earliestReview}</span>
              ) : null}
            </div>
          ) : null}

          <PersonelDosyaHero personel={personel} canViewUcret={canViewUcret} />

          {!isArchived && !isDirectoryOnly ? (
            <PersonelDosyaActionRow
              canAccessSurecler={canAccessSureclerEffective}
              canCreateSurec={canCreateSurecEffective}
              isActionMenuOpen={isActionMenuOpen}
              onToggleActionMenu={() => setIsActionMenuOpen((prev) => !prev)}
              onCloseActionMenu={() => setIsActionMenuOpen(false)}
              onOpenSurecModal={handleOpenSurecModal}
              onOpenSurecHistory={handleOpenSurecHistory}
            />
          ) : null}

          <PersonelDosyaTabPanels
            activeTab={effectiveActiveTab}
            onTabChange={setActiveTab}
            personel={personel}
            surecler={surecHistory}
            surecHistoryHasMore={surecHistoryHasMore}
            zimmetler={zimmetHistory}
            zimmetHistoryHasMore={zimmetHistoryHasMore}
            isSurecHistoryLoading={isSurecHistoryLoading}
            surecHistoryErrorMessage={surecHistoryErrorMessage}
            isZimmetHistoryLoading={isZimmetHistoryLoading}
            zimmetHistoryErrorMessage={zimmetHistoryErrorMessage}
            canViewPuantaj={canViewPuantaj && !isArchived && !isDirectoryOnly}
            canViewRevizyon={canViewRevizyon && !isArchived && !isDirectoryOnly}
            canCreateRevizyon={false}
            canCreateZimmet={false}
            canAccessSurecler={canAccessSureclerEffective || (isArchived && canViewSurecler)}
            canViewFinans={canViewFinans && !isArchived && !isDirectoryOnly}
            canViewBordro={canViewBordro && !isArchived && !isDirectoryOnly}
            canViewUcret={canViewUcret && !isDirectoryOnly}
            canManageUcret={false}
            canViewBordroKapsam={canViewBordroKapsam && !isArchived && !isDirectoryOnly}
            canManageBordroKapsam={false}
            canApproveBordroKapsam={false}
            directoryOnly={isDirectoryOnly}
          />
        </div>
      ) : null}
    </section>
  );
}
