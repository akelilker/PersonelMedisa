import { useEffect, useState, type FormEvent } from "react";
import { useLocation, useNavigate, useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import { mapUcretTipiSelectOptions } from "../../../lib/display/ucret-tipi-display";
import type { IdOption, KeyOption } from "../../../types/referans";
import { PersonelZimmetCreateForm } from "../components/PersonelZimmetCreateForm";
import {
  PersonelDosyaActionRow,
  PersonelDosyaHero,
  PersonelDosyaTabList,
  PersonelIzinDevamsizlikPanel,
  PersonelKartPanelGenelBilgiler,
  PersonelPuantajPanel,
  PersonelSurecGecmisiPanel,
  PersonelZimmetEnvanterPanel,
  type PersonelDosyaTabId
} from "../components/personel-dosya";

const PERSONEL_SUREC_FORM_ID = "personel-surec-form";
const PERSONEL_ZIMMET_FORM_ID = "personel-zimmet-form";

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

function idOptionsToSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
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
            <form className="personel-edit-form" onSubmit={handleEditSubmit}>
              <div className="form-field-grid">
                <FormField
                  label="Ad"
                  name="edit-ad"
                  value={editForm.ad}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, ad: value }))}
                  required
                />
                <FormField
                  label="Soyad"
                  name="edit-soyad"
                  value={editForm.soyad}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, soyad: value }))}
                  required
                />
                <FormField
                  label="Telefon"
                  name="edit-telefon"
                  type="tel"
                  value={editForm.telefon}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, telefon: value }))}
                />
                {personelRefs.bagliAmirOptions.length > 0 ? (
                  <>
                    <FormField
                      as="select"
                      label="Bağlı amir"
                      name="edit-bagli-amir"
                      value={editForm.bagliAmirId}
                      onChange={handleEditBagliAmirChange}
                      placeholderOption={{ value: "", label: "Seçiniz" }}
                      selectOptions={idOptionsToSelectOptions(personelRefs.bagliAmirOptions)}
                    />
                    {editBagliAmirGuidance.infoMessage ? (
                      <p className="personel-form-note personel-form-note--info">
                        {editBagliAmirGuidance.infoMessage}
                      </p>
                    ) : null}
                    {editBagliAmirGuidance.subeWarning ? (
                      <p className="personel-form-note personel-form-note--warning">
                        {editBagliAmirGuidance.subeWarning}
                      </p>
                    ) : null}
                  </>
                ) : (
                  <p className="personel-create-error">Bağlı amir listesi yüklenemedi.</p>
                )}
                {personelRefs.departmanOptions.length > 0 ? (
                  <>
                    <FormField
                      as="select"
                      label="Departman"
                      name="edit-departman"
                      value={editForm.departmanId}
                      onChange={handleEditDepartmanChange}
                      placeholderOption={{ value: "", label: "Seçiniz" }}
                      selectOptions={idOptionsToSelectOptions(personelRefs.departmanOptions)}
                    />
                    {editBagliAmirGuidance.departmanWarning ? (
                      <p className="personel-form-note personel-form-note--warning">
                        {editBagliAmirGuidance.departmanWarning}
                      </p>
                    ) : null}
                  </>
                ) : (
                  <p className="personel-create-error">Departman listesi yüklenemedi.</p>
                )}
                {personelRefs.gorevOptions.length > 0 ? (
                  <FormField
                    as="select"
                    label="Görev / Unvan"
                    name="edit-gorev"
                    value={editForm.gorevId}
                    onChange={(value) => setEditForm((prev) => ({ ...prev, gorevId: value }))}
                    placeholderOption={{ value: "", label: "Seçiniz" }}
                    selectOptions={idOptionsToSelectOptions(personelRefs.gorevOptions)}
                  />
                ) : (
                  <p className="personel-create-error">Görev / Unvan listesi yüklenemedi.</p>
                )}
                {personelRefs.ucretTipiOptions.length > 0 ? (
                  <FormField
                    as="select"
                    label="Ücret tipi"
                    name="edit-ucret-tipi-id"
                    value={editForm.ucretTipiId}
                    onChange={(value) => setEditForm((prev) => ({ ...prev, ucretTipiId: value }))}
                    placeholderOption={{ value: "", label: "Seçiniz" }}
                    selectOptions={mapUcretTipiSelectOptions(personelRefs.ucretTipiOptions)}
                  />
                ) : (
                  <p className="personel-create-error">Ücret tipi listesi yüklenemedi.</p>
                )}
                <FormField
                  label="Maaş tutarı"
                  name="edit-maas"
                  type="number"
                  min={0}
                  step="0.01"
                  value={editForm.maasTutari}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, maasTutari: value }))}
                />
                {personelRefs.primKuraliOptions.length > 0 ? (
                  <FormField
                    as="select"
                    label="Prim kuralı"
                    name="edit-prim-kurali-id"
                    value={editForm.primKuraliId}
                    onChange={(value) => setEditForm((prev) => ({ ...prev, primKuraliId: value }))}
                    placeholderOption={{ value: "", label: "Seçiniz" }}
                    selectOptions={idOptionsToSelectOptions(personelRefs.primKuraliOptions)}
                  />
                ) : (
                  <p className="personel-create-error">Prim kuralı listesi yüklenemedi.</p>
                )}
                {hasLifecycleDiff ? (
                  <FormField
                    label="Geçerlilik Tarihi"
                    name="edit-effective-date"
                    type="date"
                    value={editForm.effectiveDate}
                    onChange={(value) => setEditForm((prev) => ({ ...prev, effectiveDate: value }))}
                    required
                  />
                ) : null}
              </div>

              {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

              <div className="universal-btn-group">
                <button type="submit" className="universal-btn-save" disabled={isSubmitting}>
                  {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
                </button>
                <button type="button" className="universal-btn-cancel" onClick={discardEdit} disabled={isSubmitting}>
                  Vazgeç
                </button>
              </div>
            </form>
          ) : (
            <>
              <PersonelDosyaTabList activeTab={activeTab} onTabChange={setActiveTab} />

              <div
                id="personel-kart-panel-genel-bilgiler"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-genel-bilgiler"
                hidden={activeTab !== "genel-bilgiler"}
              >
                <PersonelKartPanelGenelBilgiler personel={personel} />
              </div>

              <div
                id="personel-kart-panel-puantaj"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-puantaj"
                hidden={activeTab !== "puantaj"}
              >
                <PersonelPuantajPanel
                  personel={personel}
                  canViewPuantaj={canViewPuantaj}
                  canViewRevizyon={canViewRevizyon}
                  isActive={activeTab === "puantaj"}
                />
              </div>

              <div
                id="personel-kart-panel-izin-devamsizlik"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-izin-devamsizlik"
                hidden={activeTab !== "izin-devamsizlik"}
              >
                <PersonelIzinDevamsizlikPanel
                  personel={personel}
                  surecler={surecHistory}
                />
              </div>

              <div
                id="personel-kart-panel-zimmet-envanter"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-zimmet-envanter"
                hidden={activeTab !== "zimmet-envanter"}
              >
                <PersonelZimmetEnvanterPanel
                  canCreateZimmet={canCreateZimmet}
                  isLoading={isZimmetHistoryLoading}
                  errorMessage={zimmetHistoryErrorMessage}
                  zimmetler={zimmetHistory}
                  onOpenCreateModal={handleOpenPersonelZimmetGateway}
                />
              </div>

              <div
                id="personel-kart-panel-surec-gecmisi"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-surec-gecmisi"
                hidden={activeTab !== "surec-gecmisi"}
              >
                <PersonelSurecGecmisiPanel
                  personel={personel}
                  canAccessSurecler={canAccessSurecler}
                  canCreateSurec={canCreateSurec}
                  isLoading={isSurecHistoryLoading}
                  errorMessage={surecHistoryErrorMessage}
                  surecler={surecHistory}
                  zimmetler={zimmetHistory}
                  onOpenCreateModal={handleOpenSurecModal}
                />
              </div>
            </>
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
