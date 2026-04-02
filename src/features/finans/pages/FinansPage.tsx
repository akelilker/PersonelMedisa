import { type FormEvent } from "react";
import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useFinans } from "../../../hooks/useFinans";
import { formatFinansKalemTuruLabel, formatFinansStateLabel } from "../../../lib/display/enum-display";
import type { FinansKalem } from "../../../types/finans";

const FINANS_CREATE_FORM_ID = "finans-create-form";
const FINANS_EDIT_FORM_ID = "finans-edit-form";

export function FinansPage() {
  const { hasPermission } = useRoleAccess();
  const canCreateFinans = hasPermission("finans.create");
  const canEditFinans = hasPermission("finans.update");
  const canCancelFinans = hasPermission("finans.cancel");

  const {
    listQuery,
    updateDraft,
    items,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createFinansHandler,
    editingItem,
    openEditModal,
    closeEditModal,
    editForm,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    cancelOngoingId,
    cancelFinansHandler,
    updateFinansHandler,
    submitFilters,
    clearFilters,
    setPage
  } = useFinans();

  const { draft } = listQuery;
  const page = listQuery.page;

  function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createFinansHandler(event, canCreateFinans);
  }

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updateFinansHandler(event, canEditFinans);
  }

  return (
    <section className="finans-page">
      <div className="finans-header-row">
        <h2>Finans</h2>
        {canCreateFinans ? (
          <button type="button" className="universal-btn-aux" onClick={openCreateModal}>
            Yeni Finans Kalemi
          </button>
        ) : null}
      </div>

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          <FormField
            label="Personel ID"
            name="finans-filter-personel"
            type="number"
            min={1}
            value={draft.personelId}
            onChange={(value) => updateDraft({ personelId: value })}
          />
          <FormField
            label="Dönem (YYYY-MM)"
            name="finans-filter-donem"
            type="month"
            value={draft.donem}
            onChange={(value) => updateDraft({ donem: value })}
          />
          <FormField
            label="Kalem Turu"
            name="finans-filter-kalem"
            placeholder="AVANS, PRİM..."
            value={draft.kalemTuru}
            onChange={(value) => updateDraft({ kalemTuru: value })}
          />
          <FormField
            label="Durum"
            name="finans-filter-state"
            placeholder="AKTIF, IPTAL..."
            value={draft.state}
            onChange={(value) => updateDraft({ state: value })}
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

      {isLoading ? <LoadingState label="Finans verileri yükleniyor..." /> : null}
      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}
      {!isLoading && !errorMessage && items.length === 0 ? (
        <EmptyState title="Finans kaydı yok" message="Bu filtrede gösterilecek finans kalemi bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && items.length > 0 ? (
        <ul className="finans-list">
          {items.map((item: FinansKalem) => (
            <li key={item.id} className="finans-item">
              <div>
                <strong>{formatFinansKalemTuruLabel(item.kalem_turu)}</strong>
                <p>Personel: {item.personel_id}</p>
                <p>Dönem: {item.donem}</p>
                <p>Tutar: {item.tutar}</p>
                <p>Durum: {formatFinansStateLabel(item.state)}</p>
              </div>

              {canEditFinans || canCancelFinans ? (
                <div className="module-item-actions">
                  {canEditFinans ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(item, canEditFinans)}
                      disabled={cancelOngoingId === item.id}
                    >
                      Düzenle
                    </button>
                  ) : null}
                  {canCancelFinans ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void cancelFinansHandler(item, canCancelFinans)}
                      disabled={cancelOngoingId === item.id}
                    >
                      {cancelOngoingId === item.id ? "İptal Ediliyor..." : "İptal"}
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
        <Link to="/raporlar">Raporlara git</Link>
        <Link to="/">Ana ekrana dön</Link>
      </div>

      {canCreateFinans && isCreateModalOpen ? (
        <AppModal
          title="Yeni Finans Kalemi"
          onClose={closeCreateModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={FINANS_CREATE_FORM_ID}
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
          <form id={FINANS_CREATE_FORM_ID} className="finans-form-grid" onSubmit={handleCreateSubmit}>
            <FormField
              label="Personel ID"
              name="finans-create-personel"
              type="number"
              min={1}
              value={createForm.personelId}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            <FormField
              label="Dönem"
              name="finans-create-donem"
              type="month"
              value={createForm.donem}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, donem: value }))}
              required
            />
            <FormField
              label="Kalem Turu"
              name="finans-create-kalem"
              value={createForm.kalemTuru}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, kalemTuru: value }))}
              required
            />
            <FormField
              label="Tutar"
              name="finans-create-tutar"
              type="number"
              min={0.01}
              step="0.01"
              value={createForm.tutar}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, tutar: value }))}
              required
            />
            <FormField
              label="Açıklama"
              name="finans-create-aciklama"
              value={createForm.aciklama}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {createErrorMessage ? <p className="finans-form-error">{createErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {canEditFinans && editingItem ? (
        <AppModal
          title={`Finans Düzenle #${editingItem.id}`}
          onClose={closeEditModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={FINANS_EDIT_FORM_ID}
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
          <form id={FINANS_EDIT_FORM_ID} className="finans-form-grid" onSubmit={handleEditSubmit}>
            <FormField
              label="Personel ID"
              name="finans-edit-personel"
              type="number"
              min={1}
              value={editForm.personelId}
              onChange={(value) => setEditForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            <FormField
              label="Dönem"
              name="finans-edit-donem"
              type="month"
              value={editForm.donem}
              onChange={(value) => setEditForm((prev) => ({ ...prev, donem: value }))}
              required
            />
            <FormField
              label="Kalem Turu"
              name="finans-edit-kalem"
              value={editForm.kalemTuru}
              onChange={(value) => setEditForm((prev) => ({ ...prev, kalemTuru: value }))}
              required
            />
            <FormField
              label="Tutar"
              name="finans-edit-tutar"
              type="number"
              min={0.01}
              step="0.01"
              value={editForm.tutar}
              onChange={(value) => setEditForm((prev) => ({ ...prev, tutar: value }))}
              required
            />
            <FormField
              label="Açıklama"
              name="finans-edit-aciklama"
              value={editForm.aciklama}
              onChange={(value) => setEditForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {editErrorMessage ? <p className="finans-form-error">{editErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
