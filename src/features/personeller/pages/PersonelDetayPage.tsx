import { useEffect, useState, type FormEvent } from "react";
import { useLocation, useNavigate, useParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import {
  PersonelDosyaActionRow,
  PersonelDosyaHero,
  PersonelDosyaTabPanels,
  PersonelInlineEditForm,
  PersonelSurecCreateModal,
  PersonelZimmetCreateModal,
  type PersonelDosyaTabId
} from "../components/personel-dosya";

export function PersonelDetayPage() {
  const location = useLocation();
  const navigate = useNavigate();
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
  const canViewFinans = hasPermission("finans.view");
  const canCreateZimmet = canEditPersonel;

  const [activeTab, setActiveTab] = useState<PersonelDosyaTabId>("genel-bilgiler");
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);

  const {
    personel,
    isLoading,
    errorMessage,
    refetch,
    isEditing,
    setIsEditing,
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
    isSurecModalOpen,
    closeSurecModal,
    surecForm,
    setSurecForm,
    createSurecHandler,
    isSurecSubmitting,
    surecCreateErrorMessage,
    surecHistory,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    surecTuruOptions,
    surecReferenceErrorMessage,
    isZimmetModalOpen,
    openZimmetModal,
    closeZimmetModal,
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    zimmetHistory,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  } = usePersonelDetail(parsedPersonelId, hasValidId, {
    canViewSurecler,
    canCreateSurec,
    canCreateZimmet
  });

  useEffect(() => {
    setActiveTab("genel-bilgiler");
    setIsActionMenuOpen(false);
  }, [parsedPersonelId]);

  useEffect(() => {
    if (isEditing || isSurecModalOpen || isZimmetModalOpen) {
      setIsActionMenuOpen(false);
    }
  }, [isEditing, isSurecModalOpen, isZimmetModalOpen]);

  useEffect(() => {
    const routeState = location.state as { openPersonelEdit?: boolean; openPersonelZimmet?: boolean } | null;
    if (!routeState?.openPersonelEdit) {
      return;
    }

    if (!canEditPersonel) {
      return;
    }

    setActiveTab("genel-bilgiler");
    setIsEditing(true);
    navigate(location.pathname, { replace: true, state: null });
  }, [canEditPersonel, location.pathname, location.state, navigate, setIsEditing]);

  useEffect(() => {
    const routeState = location.state as { openPersonelEdit?: boolean; openPersonelZimmet?: boolean } | null;
    if (!routeState?.openPersonelZimmet) {
      return;
    }

    if (!canCreateZimmet) {
      return;
    }

    setActiveTab("zimmet-envanter");
    openZimmetModal();
    navigate(location.pathname, { replace: true, state: null });
  }, [canCreateZimmet, location.pathname, location.state, navigate, openZimmetModal]);

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  function handleSurecCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createSurecHandler(event);
  }

  function handleZimmetCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createZimmetHandler(event);
  }

  function handleOpenSurecModal() {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId
        }
      }
    });
  }

  function handleOpenSurecHistory() {
    setActiveTab("surec-gecmisi");
  }

  function handleOpenPersonelEditGateway() {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "yeni-kayit",
          personelId: parsedPersonelId,
          intent: "personel-edit-gateway",
          returnTo: `/personeller/${parsedPersonelId}`
        }
      }
    });
  }

  function handleOpenPersonelZimmetGateway() {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "yeni-kayit",
          personelId: parsedPersonelId,
          intent: "personel-zimmet-gateway",
          returnTo: `/personeller/${parsedPersonelId}`
        }
      }
    });
  }

  const pageHeading =
    personel != null ? `${personel.ad} ${personel.soyad} personel dosyası` : "Personel dosyası";

  return (
    <section className="personel-detay-page personel-dosya-page" aria-label={pageHeading}>
      <h2 className="personeller-sr-only">{pageHeading}</h2>

      {isLoading ? <LoadingState label="Personel dosyası yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadı" message="Belirtilen ID ile kayıt bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          <PersonelDosyaHero personel={personel} />

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
              onSubmit={handleEditSubmit}
              onDiscard={discardEdit}
            />
          ) : (
            <PersonelDosyaTabPanels
              activeTab={activeTab}
              onTabChange={setActiveTab}
              personel={personel}
              surecler={surecHistory}
              zimmetler={zimmetHistory}
              isSurecHistoryLoading={isSurecHistoryLoading}
              surecHistoryErrorMessage={surecHistoryErrorMessage}
              isZimmetHistoryLoading={isZimmetHistoryLoading}
              zimmetHistoryErrorMessage={zimmetHistoryErrorMessage}
              canViewPuantaj={canViewPuantaj}
              canViewRevizyon={canViewRevizyon}
              canCreateZimmet={canCreateZimmet}
              canAccessSurecler={canAccessSurecler}
              canCreateSurec={canCreateSurec}
              canViewFinans={canViewFinans}
              onOpenZimmetCreate={handleOpenPersonelZimmetGateway}
              onOpenCreateSurecModal={handleOpenSurecModal}
            />
          )}
        </div>
      ) : null}

      {personel && canCreateSurec ? (
        <PersonelSurecCreateModal
          isOpen={isSurecModalOpen}
          onClose={closeSurecModal}
          onSubmit={handleSurecCreateSubmit}
          surecForm={surecForm}
          setSurecForm={setSurecForm}
          surecTuruOptions={surecTuruOptions}
          isSubmitting={isSurecSubmitting}
          surecCreateErrorMessage={surecCreateErrorMessage}
          surecReferenceErrorMessage={surecReferenceErrorMessage}
        />
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
