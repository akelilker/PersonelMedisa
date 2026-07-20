import { useEffect, useState, type FormEvent } from "react";
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
  PersonelInlineEditForm,
  PersonelZimmetCreateModal,
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
  const canEditPersonel = hasPermission("personeller.update");
  const canCreateSurec = hasPermission("surecler.create");
  const canViewSurecler = hasPermission("surecler.view") || hasPermission("surecler.view.sube");
  const canAccessSurecler = canCreateSurec || canViewSurecler;
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewRevizyon = hasPermission("revizyon.view");
  const canCreateRevizyon = hasPermission("revizyon.create");
  const canViewFinans = hasPermission("finans.view");
  const canViewBordro = hasPermission("bordro_on_izleme.view");
  const canViewUcret = hasPermission("personeller.ucret.view");
  const canManageUcret = hasPermission("personeller.ucret.manage");
  const canCreateZimmet = canEditPersonel;

  const initialTab = resolvePersonelTab(searchParams.get("tab")) ?? "genel-bilgiler";
  const [activeTab, setActiveTab] = useState<PersonelDosyaTabId>(initialTab);
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);

  const detail = usePersonelDetail(parsedPersonelId, hasValidId, {
    canViewSurecler,
    canCreateSurec,
    canCreateZimmet
  });

  const {
    personel,
    isLoading,
    errorMessage,
    refetch,
    isEditing,
    isSubmitting,
    editErrorMessage,
    editForm,
    setEditForm,
    handleEditDepartmanChange,
    handleEditBagliAmirChange,
    editBagliAmirGuidance,
    discardEdit,
    updatePersonelHandler,
    hasLifecycleDiff,
    personelRefs,
    isZimmetModalOpen,
    openZimmetModal,
    closeZimmetModal,
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    surecHistory,
    surecHistoryHasMore,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    zimmetHistory,
    zimmetHistoryHasMore,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  } = detail;

  const { handleOpenSurecModal, handleOpenPersonelEditGateway, handleOpenPersonelZimmetGateway } =
    usePersonelKartGatewayReturn({
      location,
      navigate,
      parsedPersonelId,
      canEditPersonel,
      canCreateZimmet,
      setActiveTab,
      setIsEditing: detail.setIsEditing,
      openZimmetModal
    });

  useEffect(() => {
    const routeState = location.state as {
      openPersonelEdit?: boolean;
      openPersonelZimmet?: boolean;
    } | null;

    if (routeState?.openPersonelEdit || routeState?.openPersonelZimmet) {
      setIsActionMenuOpen(false);
      return;
    }

    const fromQuery = resolvePersonelTab(searchParams.get("tab"));
    setActiveTab(fromQuery ?? "genel-bilgiler");
    setIsActionMenuOpen(false);
  }, [parsedPersonelId, searchParams]);

  useEffect(() => {
    if (isEditing || isZimmetModalOpen) {
      setIsActionMenuOpen(false);
    }
  }, [isEditing, isZimmetModalOpen]);

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  function handleZimmetCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createZimmetHandler(event);
  }

  function handleOpenSurecHistory() {
    setActiveTab("surec-gecmisi");
  }

  const pageHeading =
    personel != null
      ? `${personel.ad} ${personel.soyad} — Personel kartı detay alanı`
      : "Personel kartı detay alanı";

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
          <PersonelDosyaHero personel={personel} canViewUcret={canViewUcret} />

          {!isEditing ? (
            <PersonelDosyaActionRow
              canEditPersonel={canEditPersonel}
              canCreateZimmet={canCreateZimmet}
              canAccessSurecler={canAccessSurecler}
              canCreateSurec={canCreateSurec}
              isActionMenuOpen={isActionMenuOpen}
              onToggleActionMenu={() => setIsActionMenuOpen((prev) => !prev)}
              onCloseActionMenu={() => setIsActionMenuOpen(false)}
              onStartEdit={handleOpenPersonelEditGateway}
              onOpenZimmetCreate={handleOpenPersonelZimmetGateway}
              onOpenSurecModal={handleOpenSurecModal}
              onOpenSurecHistory={handleOpenSurecHistory}
            />
          ) : null}

          {isEditing ? (
            <PersonelInlineEditForm
              editForm={editForm}
              setEditForm={setEditForm}
              handleEditDepartmanChange={handleEditDepartmanChange}
              handleEditBagliAmirChange={handleEditBagliAmirChange}
              editBagliAmirGuidance={editBagliAmirGuidance}
              personelRefs={personelRefs}
              hasLifecycleDiff={hasLifecycleDiff}
              editErrorMessage={editErrorMessage}
              isSubmitting={isSubmitting}
              canManageUcret={canManageUcret}
              onSubmit={handleEditSubmit}
              onDiscard={discardEdit}
            />
          ) : (
            <PersonelDosyaTabPanels
              activeTab={activeTab}
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
              canViewPuantaj={canViewPuantaj}
              canViewRevizyon={canViewRevizyon}
              canCreateRevizyon={canCreateRevizyon}
              canCreateZimmet={canCreateZimmet}
              canAccessSurecler={canAccessSurecler}
              canCreateSurec={canCreateSurec}
              canViewFinans={canViewFinans}
              canViewBordro={canViewBordro}
              canViewUcret={canViewUcret}
              canManageUcret={canManageUcret}
              onOpenZimmetCreate={handleOpenPersonelZimmetGateway}
              onOpenCreateSurecModal={handleOpenSurecModal}
            />
          )}
        </div>
      ) : null}

      {personel && canCreateZimmet ? (
        <PersonelZimmetCreateModal
          isOpen={isZimmetModalOpen}
          onClose={closeZimmetModal}
          onSubmit={handleZimmetCreateSubmit}
          zimmetForm={zimmetForm}
          setZimmetForm={setZimmetForm}
          isSubmitting={isZimmetSubmitting}
          zimmetCreateErrorMessage={zimmetCreateErrorMessage}
        />
      ) : null}
    </section>
  );
}
