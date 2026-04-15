import { useEffect, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useSurecler } from "../../../hooks/useSurecler";
import { formatSurecStateLabel, formatSurecTuruLabel } from "../../../lib/display/enum-display";
import type { KeyOption } from "../../../types/referans";
import type { Surec } from "../../../types/surec";

const SUREC_CREATE_FORM_ID = "surec-create-form";
const SUREC_EDIT_FORM_ID = "surec-edit-form";

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

const UCRETLI_SELECT_OPTIONS = [
  { value: "evet", label: "Evet" },
  { value: "hayir", label: "Hayır" }
];

export function SurecTakipPage() {
  const {
    listQuery,
    updateDraft,
    surecler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    surecTuruOptions,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createSurecHandler,
    editingSurec,
    openEditModal,
    closeEditModal,
    editForm,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    updateSurecHandler,
    cancelingSurecId,
    cancelSurecHandler,
    submitFilters,
    clearFilters,
    setPage
  } = useSurecler();

  const { hasPermission } = useRoleAccess();
  const canCreateSurec = hasPermission("surecler.create");
  const canEditSurec = hasPermission("surecler.update");
  const canCancelSurec = hasPermission("surecler.cancel");
  const canOpenSurecDetail = hasPermission("surecler.detail.view");
  const location = useLocation();
  const navigate = useNavigate();

  const { draft } = listQuery;
  const page = listQuery.page;

  useEffect(() => {
    const currentState = (location.state ?? null) as Record<string, unknown> | null;
    const prefillPersonelId =
      typeof currentState?.prefillPersonelId === "number"
        ? String(currentState.prefillPersonelId)
        : typeof currentState?.prefillPersonelId === "string"
          ? currentState.prefillPersonelId
          : "";

    if (!currentState?.openCreateModal && !prefillPersonelId) {
      return;
    }

    if (prefillPersonelId) {
      updateDraft({ personelId: prefillPersonelId });
    }

    if (canCreateSurec && currentState?.openCreateModal) {
      openCreateModal();
      if (prefillPersonelId) {
        setCreateForm((prev) => ({ ...prev, personelId: prefillPersonelId }));
      }
    }

    const nextState = { ...currentState };
    delete nextState.openCreateModal;
    delete nextState.prefillPersonelId;

    navigate(location.pathname, {
      replace: true,
      state: Object.keys(nextState).length > 0 ? nextState : null
    });
  }, [canCreateSurec, location.pathname, location.state, navigate, openCreateModal, setCreateForm, updateDraft]);

  function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createSurecHandler(event, canCreateSurec);
  }

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updateSurecHandler(event, canEditSurec);
  }

  return (
    <section className="surec-page">
      <div className="surecler-header-row">
        <h2>Süreç Takibi</h2>
        {canCreateSurec ? (
          <button type="button" className="universal-btn-aux" onClick={openCreateModal}>
            Yeni Süreç
          </button>
        ) : null}
      </div>

      <SubeDetailListNotice />

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          <FormField
            label="Personel ID"
            name="surec-filter-personel"
            type="number"
            min={1}
            value={draft.personelId}
            onChange={(value) => updateDraft({ personelId: value })}
          />
          {surecTuruOptions.length > 0 ? (
            <FormField
              as="select"
              label="Süreç Türü"
              name="surec-filter-turu"
              value={draft.surecTuru}
              onChange={(value) => updateDraft({ surecTuru: value })}
              placeholderOption={{ value: "", label: "Tüm" }}
              selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
            />
          ) : (
            <FormField
              label="Süreç Türü"
              name="surec-filter-turu-text"
              placeholder="İZİN, RAPOR..."
              value={draft.surecTuru}
              onChange={(value) => updateDraft({ surecTuru: value })}
            />
          )}
          <FormField
            label="Durum"
            name="surec-filter-state"
            placeholder="AKTIF, IPTAL..."
            value={draft.state}
            onChange={(value) => updateDraft({ state: value })}
          />
          <FormField
            label="Başlangıç"
            name="surec-filter-bas"
            type="date"
            value={draft.baslangicTarihi}
            onChange={(value) => updateDraft({ baslangicTarihi: value })}
          />
          <FormField
            label="Bitiş"
            name="surec-filter-bitis"
            type="date"
            value={draft.bitisTarihi}
            onChange={(value) => updateDraft({ bitisTarihi: value })}
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux">
            Filtrele
          </button>
          <button type="button" className="universal-btn-aux" onClick={clearFilters}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Süreç verileri yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && surecler.length === 0 ? (
        <EmptyState title="Süreç kaydı yok" message="Bu filtrede gösterilecek süreç bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && surecler.length > 0 ? (
        <ul className="surecler-list">
          {surecler.map((surec: Surec) => (
            <li key={surec.id} className="surecler-item">
              <div>
                <strong>{formatSurecTuruLabel(surec.surec_turu)}</strong>
                <p>Personel: {surec.personel_id}</p>
                <p>Durum: {formatSurecStateLabel(surec.state)}</p>
                <p>
                  Tarih: {surec.baslangic_tarihi ?? "-"} / {surec.bitis_tarihi ?? "-"}
                </p>
              </div>
              {canOpenSurecDetail || canEditSurec || canCancelSurec ? (
                <div className="module-item-actions">
                  {canOpenSurecDetail ? (
                    <Link to={`/surecler/${surec.id}`} className="universal-btn-aux">
                      Detay
                    </Link>
                  ) : null}
                  {canEditSurec ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(surec, canEditSurec)}
                      disabled={cancelingSurecId === surec.id}
                    >
                      Düzenle
                    </button>
                  ) : null}
                  {canCancelSurec ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void cancelSurecHandler(surec, canCancelSurec)}
                      disabled={cancelingSurecId === surec.id}
                    >
                      {cancelingSurecId === surec.id ? "İptal Ediliyor..." : "İptal"}
                    </button>
                  ) : null}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      <div className="module-pagination">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => Math.max(1, prev - 1))}
          disabled={isLoading || page <= 1}
        >
          Önceki
        </button>
        <span className="module-page-info">
          Sayfa {page}
          {totalPages ? ` / ${totalPages}` : ""}
        </span>
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => prev + 1)}
          disabled={isLoading || !hasNextPage}
        >
          Sonraki
        </button>
      </div>

      <div className="module-links">
        <Link to="/">Ana ekrana dön</Link>
        <Link to="/bildirimler">Günlük kayıt merkezine git</Link>
        <Link to="/puantaj">Puantaja git</Link>
      </div>

      {canCreateSurec && isCreateModalOpen ? (
        <AppModal
          title="Yeni Süreç Ekle"
          onClose={closeCreateModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={SUREC_CREATE_FORM_ID}
                className="universal-btn-save"
                disabled={isCreateSubmitting}
              >
                {isCreateSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeCreateModal}
                disabled={isCreateSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={SUREC_CREATE_FORM_ID} className="surec-form-grid" onSubmit={handleCreateSubmit}>
            <FormField
              label="Personel ID"
              name="surec-create-personel"
              type="number"
              min={1}
              value={createForm.personelId}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Süreç Türü"
                name="surec-create-turu"
                value={createForm.surecTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Süreç Türü"
                name="surec-create-turu-text"
                value={createForm.surecTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, surecTuru: value }))}
                required
              />
            )}
            <FormField
              label="Alt Tur"
              name="surec-create-alt"
              value={createForm.altTur}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, altTur: value }))}
            />
            <FormField
              label="Başlangıç Tarihi"
              name="surec-create-bas"
              type="date"
              value={createForm.baslangicTarihi}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitiş Tarihi"
              name="surec-create-bitis"
              type="date"
              value={createForm.bitisTarihi}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, bitisTarihi: value }))}
              required
            />
            <FormField
              as="select"
              label="Ücretli Mi"
              name="surec-create-ucret"
              value={createForm.ucretliMi ? "evet" : "hayir"}
              onChange={(value) =>
                setCreateForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))
              }
              selectOptions={UCRETLI_SELECT_OPTIONS}
            />
            <FormField
              label="Açıklama"
              name="surec-create-aciklama"
              value={createForm.aciklama}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {createErrorMessage ? <p className="surec-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {canEditSurec && editingSurec ? (
        <AppModal
          title={`Süreç Düzenle #${editingSurec.id}`}
          onClose={closeEditModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={SUREC_EDIT_FORM_ID}
                className="universal-btn-save"
                disabled={isEditSubmitting}
              >
                {isEditSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeEditModal}
                disabled={isEditSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={SUREC_EDIT_FORM_ID} className="surec-form-grid" onSubmit={handleEditSubmit}>
            <FormField
              label="Personel ID"
              name="surec-edit-personel"
              type="number"
              min={1}
              value={editForm.personelId}
              onChange={(value) => setEditForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Süreç Türü"
                name="surec-edit-turu"
                value={editForm.surecTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Süreç Türü"
                name="surec-edit-turu-text"
                value={editForm.surecTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, surecTuru: value }))}
                required
              />
            )}
            <FormField
              label="Alt Tur"
              name="surec-edit-alt"
              value={editForm.altTur}
              onChange={(value) => setEditForm((prev) => ({ ...prev, altTur: value }))}
            />
            <FormField
              label="Başlangıç Tarihi"
              name="surec-edit-start"
              type="date"
              value={editForm.baslangicTarihi}
              onChange={(value) => setEditForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitiş Tarihi"
              name="surec-edit-end"
              type="date"
              value={editForm.bitisTarihi}
              onChange={(value) => setEditForm((prev) => ({ ...prev, bitisTarihi: value }))}
              required
            />
            <FormField
              as="select"
              label="Ücretli Mi"
              name="surec-edit-ucret"
              value={editForm.ucretliMi ? "evet" : "hayir"}
              onChange={(value) =>
                setEditForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))
              }
              selectOptions={UCRETLI_SELECT_OPTIONS}
            />
            <FormField
              label="Açıklama"
              name="surec-edit-aciklama"
              value={editForm.aciklama}
              onChange={(value) => setEditForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {editErrorMessage ? <p className="surec-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
