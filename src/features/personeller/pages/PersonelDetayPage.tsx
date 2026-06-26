import { useEffect, useState, type FormEvent } from "react";
import { useLocation, useNavigate, useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import type { KeyOption } from "../../../types/referans";
import { PersonelZimmetCreateForm } from "../components/PersonelZimmetCreateForm";
import {
  PersonelDosyaActionRow,
  PersonelDosyaHero,
  PersonelDosyaTabPanels,
  PersonelInlineEditForm,
  type PersonelDosyaTabId
} from "../components/personel-dosya";

const PERSONEL_SUREC_FORM_ID = "personel-surec-form";
const PERSONEL_ZIMMET_FORM_ID = "personel-zimmet-form";

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

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
              onOpenZimmetCreate={handleOpenPersonelZimmetGateway}
              onOpenCreateSurecModal={handleOpenSurecModal}
            />
          )}
        </div>
      ) : null}

      {personel && canCreateSurec && isSurecModalOpen ? (
        <AppModal
          title="Süreç Ekle"
          onClose={closeSurecModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_SUREC_FORM_ID}
                className="universal-btn-save"
                disabled={isSurecSubmitting}
              >
                {isSurecSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeSurecModal}
                disabled={isSurecSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={PERSONEL_SUREC_FORM_ID} className="personel-surec-form-grid" onSubmit={handleSurecCreateSubmit}>
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Süreç Türü"
                name="personel-surec-turu"
                value={surecForm.surecTuru}
                onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Süreç Türü"
                name="personel-surec-turu-text"
                value={surecForm.surecTuru}
                onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholder="IZIN, RAPOR, ISTEN_AYRILMA"
              />
            )}
            <FormField
              label="Başlangıç Tarihi"
              name="personel-surec-baslangic"
              type="date"
              value={surecForm.baslangicTarihi}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitiş Tarihi"
              name="personel-surec-bitis"
              type="date"
              value={surecForm.bitisTarihi}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, bitisTarihi: value }))}
            />
            <FormField
              as="textarea"
              label="Açıklama"
              name="personel-surec-aciklama"
              value={surecForm.aciklama}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, aciklama: value }))}
              rows={4}
            />
            {surecCreateErrorMessage ? <p className="personel-create-error">{surecCreateErrorMessage}</p> : null}
            {surecReferenceErrorMessage ? <p className="personel-create-error">{surecReferenceErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {personel && canCreateZimmet && isZimmetModalOpen ? (
        <AppModal
          title="Yeni Zimmet Ekle"
          onClose={closeZimmetModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_ZIMMET_FORM_ID}
                className="universal-btn-save"
                disabled={isZimmetSubmitting}
              >
                {isZimmetSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeZimmetModal}
                disabled={isZimmetSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <PersonelZimmetCreateForm
            formId={PERSONEL_ZIMMET_FORM_ID}
            zimmetForm={zimmetForm}
            setZimmetForm={setZimmetForm}
            onSubmit={handleZimmetCreateSubmit}
            zimmetCreateErrorMessage={zimmetCreateErrorMessage}
          />
        </AppModal>
      ) : null}
    </section>
  );
}
