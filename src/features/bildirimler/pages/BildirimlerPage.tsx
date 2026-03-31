import { type FormEvent } from "react";
import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useBildirimler } from "../../../hooks/useBildirimler";
import type { IdOption, KeyOption } from "../../../types/referans";
import type { Bildirim } from "../../../types/bildirim";

const BILDIRIM_CREATE_FORM_ID = "bildirim-create-form";
const BILDIRIM_EDIT_FORM_ID = "bildirim-edit-form";

function idOptionsToSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

export function BildirimlerPage() {
  const {
    listQuery,
    updateDraft,
    bildirimler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    departmanOptions,
    bildirimTuruOptions,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createBildirimHandler,
    editingBildirim,
    openEditModal,
    closeEditModal,
    editForm,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    updateBildirimHandler,
    cancelingBildirimId,
    cancelBildirimHandler,
    submitFilters,
    clearFilters,
    setPage
  } = useBildirimler();

  const { hasPermission } = useRoleAccess();
  const canCreateBildirim = hasPermission("bildirimler.create");
  const canEditBildirim = hasPermission("bildirimler.update");
  const canCancelBildirim = hasPermission("bildirimler.cancel");
  const canOpenBildirimDetail = hasPermission("bildirimler.detail.view");

  const { draft } = listQuery;
  const page = listQuery.page;

  function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createBildirimHandler(event, canCreateBildirim);
  }

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updateBildirimHandler(event, canEditBildirim);
  }

  return (
    <section className="bildirimler-page">
      <div className="bildirimler-header-row">
        <h2>Bildirimler</h2>
        {canCreateBildirim ? (
          <button type="button" className="universal-btn-aux" onClick={openCreateModal}>
            Yeni Bildirim
          </button>
        ) : null}
      </div>

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          <FormField
            label="Personel ID"
            name="bildirim-filter-personel"
            type="number"
            min={1}
            value={draft.personelId}
            onChange={(value) => updateDraft({ personelId: value })}
          />
          {bildirimTuruOptions.length > 0 ? (
            <FormField
              as="select"
              label="Bildirim Turu"
              name="bildirim-filter-turu"
              value={draft.bildirimTuru}
              onChange={(value) => updateDraft({ bildirimTuru: value })}
              placeholderOption={{ value: "", label: "Tum" }}
              selectOptions={keyOptionsToSelectOptions(bildirimTuruOptions)}
            />
          ) : (
            <FormField
              label="Bildirim Turu"
              name="bildirim-filter-turu-text"
              placeholder="GEC_GELDI, DEVAMSIZLIK..."
              value={draft.bildirimTuru}
              onChange={(value) => updateDraft({ bildirimTuru: value })}
            />
          )}
          <FormField
            label="Tarih"
            name="bildirim-filter-tarih"
            type="date"
            value={draft.tarih}
            onChange={(value) => updateDraft({ tarih: value })}
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

      {isLoading ? <LoadingState label="Bildirim verileri yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length === 0 ? (
        <EmptyState
          title="Bildirim bulunamadi"
          message="Secilen tarihte veya filtrede bildirim kaydi yok."
        />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length > 0 ? (
        <ul className="bildirimler-list">
          {bildirimler.map((bildirim: Bildirim) => (
            <li key={bildirim.id} className="bildirimler-item">
              <div>
                <strong>{bildirim.bildirim_turu}</strong>
                <p>Tarih: {bildirim.tarih ?? "-"}</p>
                <p>Personel: {bildirim.personel_id ?? "-"}</p>
              </div>
              {canOpenBildirimDetail || canEditBildirim || canCancelBildirim ? (
                <div className="module-item-actions">
                  {canOpenBildirimDetail ? (
                    <Link to={`/bildirimler/${bildirim.id}`} className="universal-btn-aux">
                      Detay
                    </Link>
                  ) : null}
                  {canEditBildirim ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(bildirim, canEditBildirim)}
                      disabled={cancelingBildirimId === bildirim.id}
                    >
                      Duzenle
                    </button>
                  ) : null}
                  {canCancelBildirim ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void cancelBildirimHandler(bildirim, canCancelBildirim)}
                      disabled={cancelingBildirimId === bildirim.id}
                    >
                      {cancelingBildirimId === bildirim.id ? "Iptal Ediliyor..." : "Iptal"}
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
          Onceki
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
        <Link to="/personeller">Personellere don</Link>
        <Link to="/surecler">Surec takibe git</Link>
        <Link to="/puantaj">Puantaja git</Link>
      </div>

      {canCreateBildirim && isCreateModalOpen ? (
        <AppModal
          title="Yeni Bildirim Ekle"
          onClose={closeCreateModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={BILDIRIM_CREATE_FORM_ID}
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
                Vazgec
              </button>
            </div>
          }
        >
          <form id={BILDIRIM_CREATE_FORM_ID} className="bildirim-form-grid" onSubmit={handleCreateSubmit}>
            <FormField
              label="Tarih"
              name="bildirim-create-tarih"
              type="date"
              value={createForm.tarih}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, tarih: value }))}
              required
            />
            {departmanOptions.length > 0 ? (
              <FormField
                as="select"
                label="Departman ID"
                name="bildirim-create-departman"
                value={createForm.departmanId}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={idOptionsToSelectOptions(departmanOptions)}
              />
            ) : (
              <FormField
                label="Departman ID"
                name="bildirim-create-departman-num"
                type="number"
                min={1}
                value={createForm.departmanId}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                required
              />
            )}
            <FormField
              label="Personel ID"
              name="bildirim-create-personel"
              type="number"
              min={1}
              value={createForm.personelId}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            {bildirimTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Bildirim Turu"
                name="bildirim-create-turu"
                value={createForm.bildirimTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={keyOptionsToSelectOptions(bildirimTuruOptions)}
              />
            ) : (
              <FormField
                label="Bildirim Turu"
                name="bildirim-create-turu-text"
                value={createForm.bildirimTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
              />
            )}
            <FormField
              label="Aciklama"
              name="bildirim-create-aciklama"
              value={createForm.aciklama}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {createErrorMessage ? <p className="bildirim-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {canEditBildirim && editingBildirim ? (
        <AppModal
          title={`Bildirim Duzenle #${editingBildirim.id}`}
          onClose={closeEditModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={BILDIRIM_EDIT_FORM_ID}
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
                Vazgec
              </button>
            </div>
          }
        >
          <form id={BILDIRIM_EDIT_FORM_ID} className="bildirim-form-grid" onSubmit={handleEditSubmit}>
            <FormField
              label="Tarih"
              name="bildirim-edit-tarih"
              type="date"
              value={editForm.tarih}
              onChange={(value) => setEditForm((prev) => ({ ...prev, tarih: value }))}
              required
            />
            {departmanOptions.length > 0 ? (
              <FormField
                as="select"
                label="Departman ID"
                name="bildirim-edit-departman"
                value={editForm.departmanId}
                onChange={(value) => setEditForm((prev) => ({ ...prev, departmanId: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={idOptionsToSelectOptions(departmanOptions)}
              />
            ) : (
              <FormField
                label="Departman ID"
                name="bildirim-edit-departman-num"
                type="number"
                min={1}
                value={editForm.departmanId}
                onChange={(value) => setEditForm((prev) => ({ ...prev, departmanId: value }))}
                required
              />
            )}
            <FormField
              label="Personel ID"
              name="bildirim-edit-personel"
              type="number"
              min={1}
              value={editForm.personelId}
              onChange={(value) => setEditForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            {bildirimTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Bildirim Turu"
                name="bildirim-edit-turu"
                value={editForm.bildirimTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={keyOptionsToSelectOptions(bildirimTuruOptions)}
              />
            ) : (
              <FormField
                label="Bildirim Turu"
                name="bildirim-edit-turu-text"
                value={editForm.bildirimTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
              />
            )}
            <FormField
              label="Aciklama"
              name="bildirim-edit-aciklama"
              value={editForm.aciklama}
              onChange={(value) => setEditForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {editErrorMessage ? <p className="bildirim-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
