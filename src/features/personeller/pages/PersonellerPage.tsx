import { useEffect, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { AppModal } from "../../../components/modal/AppModal";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersoneller, type CreatePersonelFormState } from "../../../hooks/usePersoneller";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";

const PERSONEL_CREATE_FORM_ID = "personel-create-form";

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

export function PersonellerPage() {
  const {
    listQuery,
    personeller,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    refs,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    isCreateSubmitting,
    createErrorMessage,
    createForm,
    setCreateForm,
    createPersonelHandler,
    submitFilters,
    clearFilters,
    setDraftSearch,
    setDraftAktiflik,
    setPage
  } = usePersoneller();

  const { hasPermission } = useRoleAccess();
  const canCreatePersonel = hasPermission("personeller.create");
  const canOpenDetail = hasPermission("personeller.detail.view");
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    const currentState = (location.state ?? null) as Record<string, unknown> | null;
    if (!currentState?.openCreateModal) {
      return;
    }

    if (canCreatePersonel) {
      openCreateModal();
    }

    const nextState = { ...currentState };
    delete nextState.openCreateModal;

    navigate(location.pathname, {
      replace: true,
      state: Object.keys(nextState).length > 0 ? nextState : null
    });
  }, [canCreatePersonel, location.pathname, location.state, navigate, openCreateModal]);

  function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createPersonelHandler(event, canCreatePersonel);
  }

  const aktiflikSelectOptions = [
    { value: "tum", label: "Tum" },
    { value: "aktif", label: "Aktif" },
    { value: "pasif", label: "Pasif" }
  ];

  const aktifDurumOptions = [
    { value: "AKTIF", label: "AKTIF" },
    { value: "PASIF", label: "PASIF" }
  ];
  const kanGrubuOptions = [
    { value: "A Rh+", label: "A Rh+" },
    { value: "A Rh-", label: "A Rh-" },
    { value: "B Rh+", label: "B Rh+" },
    { value: "B Rh-", label: "B Rh-" },
    { value: "AB Rh+", label: "AB Rh+" },
    { value: "AB Rh-", label: "AB Rh-" },
    { value: "0 Rh+", label: "0 Rh+" },
    { value: "0 Rh-", label: "0 Rh-" }
  ];

  const { draft } = listQuery;
  const page = listQuery.page;

  return (
    <section className="personeller-page">
      <div className="personeller-header-row">
        <h2>Personeller</h2>
        {canCreatePersonel ? (
          <button type="button" className="universal-btn-aux" onClick={openCreateModal}>
            Yeni Personel
          </button>
        ) : null}
      </div>

      <SubeDetailListNotice />

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          <FormField
            label="Ara"
            name="personel-filter-search"
            placeholder="Ad, soyad veya T.C. Kimlik No"
            value={draft.search}
            onChange={setDraftSearch}
          />
          <FormField
            as="select"
            label="Aktiflik"
            name="personel-filter-aktiflik"
            value={draft.aktiflik}
            onChange={(value) => setDraftAktiflik(value as "aktif" | "pasif" | "tum")}
            selectOptions={aktiflikSelectOptions}
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

      {isLoading ? <LoadingState label="Personel verileri yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && personeller.length === 0 ? (
        <EmptyState
          title="Personel kaydi bulunamadi"
          message="Filtre veya kaynak veri durumunu kontrol et."
        />
      ) : null}

      {!isLoading && !errorMessage && personeller.length > 0 ? (
        <ul className="personeller-list">
          {personeller.map((personel: Personel) => (
            <li key={personel.id} className="personeller-item">
              <div>
                <strong>{`${personel.ad} ${personel.soyad}`}</strong>
                <p>Durum: {personel.aktif_durum}</p>
              </div>
              {canOpenDetail ? <Link to={`/personeller/${personel.id}`}>Detay</Link> : null}
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
        <Link to="/surecler">Surec takibe git</Link>
        <Link to="/bildirimler">Bildirimlere git</Link>
        <Link to="/puantaj">Puantaja git</Link>
      </div>

      {canCreatePersonel && isCreateModalOpen ? (
        <AppModal
          title="Yeni Personel Ekle"
          onClose={closeCreateModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_CREATE_FORM_ID}
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
          <form
            id={PERSONEL_CREATE_FORM_ID}
            className="personel-create-form"
            onSubmit={handleCreateSubmit}
          >
            <div className="personel-create-grid">
              <FormField
                label="T.C. Kimlik No"
                name="create-tc"
                value={createForm.tcKimlikNo}
                onChange={(value) => setCreateForm((prev: CreatePersonelFormState) => ({ ...prev, tcKimlikNo: value }))}
                required
              />
              <FormField
                label="Ad"
                name="create-ad"
                value={createForm.ad}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, ad: value }))}
                required
              />
              <FormField
                label="Soyad"
                name="create-soyad"
                value={createForm.soyad}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, soyad: value }))}
                required
              />
              <FormField
                label="Dogum Tarihi"
                name="create-dogum"
                type="date"
                value={createForm.dogumTarihi}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, dogumTarihi: value }))}
                required
              />
              <FormField
                label="Telefon"
                name="create-telefon"
                type="tel"
                value={createForm.telefon}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, telefon: value }))}
                required
              />
              <FormField
                label="Acil Durum Kisi"
                name="create-acil-kisi"
                value={createForm.acilDurumKisi}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, acilDurumKisi: value }))}
                required
              />
              <FormField
                label="Acil Durum Telefon"
                name="create-acil-tel"
                type="tel"
                value={createForm.acilDurumTelefon}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, acilDurumTelefon: value }))}
                required
              />
              <FormField
                label="Dogum Yeri"
                name="create-dogum-yeri"
                value={createForm.dogumYeri}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, dogumYeri: value }))}
              />
              <FormField
                as="select"
                label="Kan Grubu"
                name="create-kan"
                value={createForm.kanGrubu}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, kanGrubu: value }))}
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={kanGrubuOptions}
              />
              <FormField
                label="Sicil No"
                name="create-sicil"
                value={createForm.sicilNo}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, sicilNo: value }))}
                required
              />
              <FormField
                label="Ise Giris Tarihi"
                name="create-ise-giris"
                type="date"
                value={createForm.iseGirisTarihi}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, iseGirisTarihi: value }))}
                required
              />
              {refs.departmanOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Departman ID"
                  name="create-departman"
                  value={createForm.departmanId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.departmanOptions)}
                />
              ) : (
                <FormField
                  label="Departman ID"
                  name="create-departman-num"
                  type="number"
                  min={1}
                  value={createForm.departmanId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                  required
                />
              )}
              {refs.gorevOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Gorev ID"
                  name="create-gorev"
                  value={createForm.gorevId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, gorevId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.gorevOptions)}
                />
              ) : (
                <FormField
                  label="Gorev ID"
                  name="create-gorev-num"
                  type="number"
                  min={1}
                  value={createForm.gorevId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, gorevId: value }))}
                  required
                />
              )}
              {refs.bagliAmirOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Bagli Amir"
                  name="create-bagli-amir"
                  value={createForm.bagliAmirId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, bagliAmirId: value }))}
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.bagliAmirOptions)}
                />
              ) : (
                <FormField
                  label="Bagli Amir"
                  name="create-bagli-amir-num"
                  type="number"
                  min={1}
                  value={createForm.bagliAmirId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, bagliAmirId: value }))}
                />
              )}
              {refs.personelTipiOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Personel Tipi ID"
                  name="create-personel-tipi"
                  value={createForm.personelTipiId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, personelTipiId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.personelTipiOptions)}
                />
              ) : (
                <FormField
                  label="Personel Tipi ID"
                  name="create-personel-tipi-num"
                  type="number"
                  min={1}
                  value={createForm.personelTipiId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, personelTipiId: value }))}
                  required
                />
              )}
              <FormField
                as="select"
                label="Aktif Durum"
                name="create-aktif-durum"
                value={createForm.aktifDurum}
                onChange={(value) =>
                  setCreateForm((prev) => ({ ...prev, aktifDurum: value as "AKTIF" | "PASIF" }))
                }
                selectOptions={aktifDurumOptions}
              />
            </div>

            {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
