import { useEffect, useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { AppModal } from "../../../components/modal/AppModal";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersoneller, type CreatePersonelFormState } from "../../../hooks/usePersoneller";
import { formatAktifDurumLabel } from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";

const PERSONEL_CREATE_FORM_ID = "personel-create-form";

function IconSearch(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <circle cx="11" cy="11" r="8" />
      <path d="m21 21-4.3-4.3" />
    </svg>
  );
}

function IconList(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
    </svg>
  );
}

function IconGrid(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <rect x="3" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="3" width="7" height="7" rx="1" />
      <rect x="3" y="14" width="7" height="7" rx="1" />
      <rect x="14" y="14" width="7" height="7" rx="1" />
    </svg>
  );
}

function IconFilter(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z" />
    </svg>
  );
}

function IconBack(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="m15 18-6-6 6-6" />
    </svg>
  );
}

function IconMenu(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M4 6h16M4 12h16M4 18h16" />
    </svg>
  );
}

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

function digitsOnly(value: string | null | undefined) {
  return (value ?? "").replace(/\D+/g, "");
}

function buildTelHref(value: string | null | undefined) {
  const digits = digitsOnly(value);
  return digits ? `tel:${digits}` : null;
}

function formatReferenceValue(label: string | undefined, id: number | undefined) {
  if (label) {
    return label;
  }

  return typeof id === "number" ? `#${id}` : "-";
}

function personelGridSubtitle(personel: Personel) {
  const gorev = formatReferenceValue(personel.gorev_adi, personel.gorev_id);
  const tip = personel.personel_tipi_adi?.trim();
  if (tip && gorev !== "-") {
    return `${gorev} · ${tip}`;
  }
  if (tip) {
    return tip;
  }
  return gorev;
}

function personelGridMutedLine(personel: Personel) {
  const dept = formatReferenceValue(personel.departman_adi, personel.departman_id);
  const sube = personel.sube_adi?.trim();
  if (sube && dept !== "-") {
    return `${dept} · ${sube}`;
  }
  if (sube) {
    return sube;
  }
  return dept;
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
    setDraftDepartmanId,
    setDraftPersonelTipiId,
    setPage
  } = usePersoneller();

  const { hasPermission } = useRoleAccess();
  const canCreatePersonel = hasPermission("personeller.create");
  const canOpenDetail = hasPermission("personeller.detail.view");
  const location = useLocation();
  const navigate = useNavigate();
  const [searchExpanded, setSearchExpanded] = useState(false);
  const [filterExpanded, setFilterExpanded] = useState(false);
  const [moduleMenuOpen, setModuleMenuOpen] = useState(false);
  const [viewMode, setViewMode] = useState<"list" | "grid">("grid");

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

  const aktifDurumOptions = [
    { value: "AKTIF", label: formatAktifDurumLabel("AKTIF") },
    { value: "PASIF", label: formatAktifDurumLabel("PASIF") }
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
  const departmanFilterOptions = toSelectOptions(refs.departmanOptions);
  const personelTipiFilterOptions = toSelectOptions(refs.personelTipiOptions);

  return (
    <section className="personeller-page" aria-labelledby="personeller-page-heading">
      <h2 id="personeller-page-heading" className="personeller-sr-only">
        Personeller
      </h2>

      <div className="personeller-toolbar">
        <div className="personeller-toolbar-left">
          <button
            type="button"
            className="personeller-icon-btn"
            aria-label="Onceki sayfaya don"
            onClick={() => navigate(-1)}
          >
            <IconBack />
          </button>
        </div>
        <div className="personeller-toolbar-right">
          <button
            type="button"
            className="personeller-icon-btn"
            aria-expanded={searchExpanded}
            aria-controls="personeller-filter-form"
            aria-label={searchExpanded ? "Aramayi kapat" : "Arama ac"}
            onClick={() => setSearchExpanded((open) => !open)}
          >
            <IconSearch />
          </button>
          <button
            type="button"
            className="personeller-icon-btn"
            aria-expanded={filterExpanded}
            aria-controls="personeller-filter-form"
            aria-label={filterExpanded ? "Detayli filtreyi kapat" : "Detayli filtre ac"}
            onClick={() => setFilterExpanded((open) => !open)}
          >
            <IconFilter />
          </button>
          <div className="personeller-view-toggle" role="group" aria-label="Liste gorunumu">
            <button
              type="button"
              className="personeller-icon-btn"
              aria-pressed={viewMode === "list"}
              aria-label="Tablo listesi"
              onClick={() => setViewMode("list")}
            >
              <IconList />
            </button>
            <button
              type="button"
              className="personeller-icon-btn"
              aria-pressed={viewMode === "grid"}
              aria-label="Izgara kartlari"
              onClick={() => setViewMode("grid")}
            >
              <IconGrid />
            </button>
          </div>
          <div className="personeller-toolbar-menu-host">
            <button
              type="button"
              className="personeller-icon-btn"
              aria-expanded={moduleMenuOpen}
              aria-controls="personeller-module-menu"
              aria-haspopup="true"
              aria-label="Modul menu"
              onClick={() => setModuleMenuOpen((open) => !open)}
            >
              <IconMenu />
            </button>
            {moduleMenuOpen ? (
              <div
                id="personeller-module-menu"
                className="personeller-module-flyout"
                role="menu"
              >
                <Link
                  to="/surecler"
                  className="personeller-module-flyout-link"
                  role="menuitem"
                  onClick={() => setModuleMenuOpen(false)}
                >
                  Surec takibi
                </Link>
                <Link
                  to="/bildirimler"
                  className="personeller-module-flyout-link"
                  role="menuitem"
                  onClick={() => setModuleMenuOpen(false)}
                >
                  Bildirimler
                </Link>
                <Link
                  to="/puantaj"
                  className="personeller-module-flyout-link"
                  role="menuitem"
                  onClick={() => setModuleMenuOpen(false)}
                >
                  Puantaj
                </Link>
              </div>
            ) : null}
          </div>
          {canCreatePersonel ? (
            <button
              type="button"
              className="personeller-add-icon-btn"
              onClick={openCreateModal}
              aria-label="Yeni personel ekle"
            >
              +
            </button>
          ) : null}
        </div>
      </div>

      <SubeDetailListNotice />

      {searchExpanded || filterExpanded ? (
        <form
          id="personeller-filter-form"
          className="personeller-filter-panel"
          onSubmit={submitFilters}
        >
          {searchExpanded ? (
            <div className="personeller-filter-search form-field-grid">
              <FormField
                label="Ara"
                name="personel-filter-search"
                placeholder="Ad, soyad veya T.C. Kimlik No"
                value={draft.search}
                onChange={setDraftSearch}
              />
            </div>
          ) : null}

          {filterExpanded ? (
            <>
              <div className="personeller-filter-primary form-field-grid">
                {departmanFilterOptions.length > 0 ? (
                  <FormField
                    as="select"
                    label="Bolum"
                    name="personel-filter-departman"
                    value={draft.departmanId}
                    onChange={setDraftDepartmanId}
                    placeholderOption={{ value: "", label: "Tumu" }}
                    selectOptions={departmanFilterOptions}
                  />
                ) : (
                  <FormField
                    label="Bolum"
                    name="personel-filter-departman-num"
                    type="number"
                    min={1}
                    placeholder="Tumu"
                    value={draft.departmanId}
                    onChange={setDraftDepartmanId}
                  />
                )}
              </div>

              <div className="personeller-filter-secondary">
                {personelTipiFilterOptions.length > 0 ? (
                  <FormField
                    as="select"
                    label="Personel tipi"
                    name="personel-filter-personel-tipi"
                    value={draft.personelTipiId}
                    onChange={setDraftPersonelTipiId}
                    placeholderOption={{ value: "", label: "Tumu" }}
                    selectOptions={personelTipiFilterOptions}
                  />
                ) : (
                  <FormField
                    label="Personel tipi"
                    name="personel-filter-personel-tipi-num"
                    type="number"
                    min={1}
                    placeholder="Tumu"
                    value={draft.personelTipiId}
                    onChange={setDraftPersonelTipiId}
                  />
                )}
                <div className="personeller-aktiflik-group" role="group" aria-label="Aktiflik">
                  <span className="personeller-aktiflik-label">Aktiflik</span>
                  <div className="personeller-aktiflik-checks">
                    <label className="personeller-checkbox-inline">
                      <input
                        type="checkbox"
                        name="personel-filter-aktif"
                        checked={draft.aktiflik === "aktif"}
                        onChange={(event) => {
                          if (event.target.checked) {
                            setDraftAktiflik("aktif");
                          } else if (draft.aktiflik === "aktif") {
                            setDraftAktiflik("tum");
                          }
                        }}
                      />
                      <span>Aktif</span>
                    </label>
                    <label className="personeller-checkbox-inline">
                      <input
                        type="checkbox"
                        name="personel-filter-pasif"
                        checked={draft.aktiflik === "pasif"}
                        onChange={(event) => {
                          if (event.target.checked) {
                            setDraftAktiflik("pasif");
                          } else if (draft.aktiflik === "pasif") {
                            setDraftAktiflik("tum");
                          }
                        }}
                      />
                      <span>Pasif</span>
                    </label>
                  </div>
                </div>
              </div>
            </>
          ) : null}

          <div className="form-actions-row personeller-filter-actions">
            <button type="submit" className="universal-btn-aux">
              Filtrele
            </button>
            <button type="button" className="universal-btn-aux" onClick={clearFilters}>
              Temizle
            </button>
          </div>
        </form>
      ) : null}

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

      {!isLoading && !errorMessage && personeller.length > 0 && viewMode === "list" ? (
        <div className="personeller-table-wrap">
          <table className="personeller-table">
            <thead>
              <tr>
                <th scope="col">Ad Soyad</th>
                <th scope="col">Bolum</th>
                <th scope="col">Gorev</th>
                <th scope="col">Durum</th>
                <th scope="col">Telefon</th>
                <th scope="col" className="personeller-table-col-actions">
                  Hizli
                </th>
              </tr>
            </thead>
            <tbody>
              {personeller.map((personel: Personel) => {
                const personelCallHref = buildTelHref(personel.telefon);
                const emergencyCallHref = buildTelHref(personel.acil_durum_telefon);
                const detailTo = `/personeller/${personel.id}`;
                const previewLabel = `${personel.ad} ${personel.soyad} kisisi kartini ac`;

                function rowActivate() {
                  if (canOpenDetail) {
                    void navigate(detailTo);
                  }
                }

                return (
                  <tr
                    key={personel.id}
                    className={canOpenDetail ? "personeller-table-row-clickable" : undefined}
                    onClick={(event) => {
                      if (!canOpenDetail) {
                        return;
                      }
                      if ((event.target as HTMLElement).closest("a")) {
                        return;
                      }
                      rowActivate();
                    }}
                    onKeyDown={(event) => {
                      if (!canOpenDetail) {
                        return;
                      }
                      if (event.key !== "Enter" && event.key !== " ") {
                        return;
                      }
                      if ((event.target as HTMLElement).closest("a")) {
                        return;
                      }
                      event.preventDefault();
                      rowActivate();
                    }}
                    tabIndex={canOpenDetail ? 0 : undefined}
                    aria-label={canOpenDetail ? previewLabel : undefined}
                  >
                    <td className="personeller-table-cell-strong">
                      {canOpenDetail ? (
                        <Link
                          className="personeller-table-name-link"
                          to={detailTo}
                          aria-label={previewLabel}
                          onClick={(event) => event.stopPropagation()}
                        >
                          {`${personel.ad} ${personel.soyad}`}
                        </Link>
                      ) : (
                        `${personel.ad} ${personel.soyad}`
                      )}
                    </td>
                    <td title={formatReferenceValue(personel.departman_adi, personel.departman_id)}>
                      {formatReferenceValue(personel.departman_adi, personel.departman_id)}
                    </td>
                    <td title={formatReferenceValue(personel.gorev_adi, personel.gorev_id)}>
                      {formatReferenceValue(personel.gorev_adi, personel.gorev_id)}
                    </td>
                    <td>{formatAktifDurumLabel(personel.aktif_durum)}</td>
                    <td>{personel.telefon ?? "-"}</td>
                    <td className="personeller-table-col-actions">
                      <div className="personeller-table-actions">
                        {personelCallHref ? (
                          <a
                            className="universal-btn-aux personeller-table-action-btn"
                            href={personelCallHref}
                            onClick={(event) => event.stopPropagation()}
                          >
                            Ara
                          </a>
                        ) : null}
                        {emergencyCallHref ? (
                          <a
                            className="universal-btn-aux personeller-table-action-btn"
                            href={emergencyCallHref}
                            onClick={(event) => event.stopPropagation()}
                          >
                            Acil
                          </a>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      {!isLoading && !errorMessage && personeller.length > 0 && viewMode === "grid" ? (
        <div className="personeller-list-wrap personeller-list-wrap--grid">
          <ul className="personeller-list personeller-list--grid">
            {personeller.map((personel: Personel) => {
              const personelCallHref = buildTelHref(personel.telefon);
              const emergencyCallHref = buildTelHref(personel.acil_durum_telefon);
              const hasQuickActions = Boolean(personelCallHref || emergencyCallHref);
              const detailTo = `/personeller/${personel.id}`;
              const previewLabel = `${personel.ad} ${personel.soyad} kisisi kartini ac`;

              const previewInner = (
                <div className="personeller-item-content personeller-item-content--grid">
                  <span className="personeller-card-title">{`${personel.ad} ${personel.soyad}`}</span>
                  <span className="personeller-card-sub">{personelGridSubtitle(personel)}</span>
                  <span className="personeller-card-muted">{personelGridMutedLine(personel)}</span>
                </div>
              );

              return (
                <li key={personel.id} className="personeller-item personeller-item--grid">
                  {canOpenDetail ? (
                    <Link className="personeller-card-preview" to={detailTo} aria-label={previewLabel}>
                      {previewInner}
                    </Link>
                  ) : (
                    <div className="personeller-card-preview-static">{previewInner}</div>
                  )}
                  {hasQuickActions ? (
                    <div className="module-item-actions personeller-card-actions">
                      {personelCallHref ? (
                        <a className="universal-btn-aux" href={personelCallHref}>
                          Ara
                        </a>
                      ) : null}
                      {emergencyCallHref ? (
                        <a className="universal-btn-aux" href={emergencyCallHref}>
                          Acil Ara
                        </a>
                      ) : null}
                    </div>
                  ) : null}
                </li>
              );
            })}
          </ul>
        </div>
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
                onChange={(value) =>
                  setCreateForm((prev: CreatePersonelFormState) => ({ ...prev, tcKimlikNo: value }))
                }
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
                label="Acil Durum Kisisi"
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
                  label="Bolum"
                  name="create-departman"
                  value={createForm.departmanId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.departmanOptions)}
                />
              ) : (
                <FormField
                  label="Bolum"
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
                  label="Gorev"
                  name="create-gorev"
                  value={createForm.gorevId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, gorevId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.gorevOptions)}
                />
              ) : (
                <FormField
                  label="Gorev"
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
                  label="Personel Tipi"
                  name="create-personel-tipi"
                  value={createForm.personelTipiId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, personelTipiId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(refs.personelTipiOptions)}
                />
              ) : (
                <FormField
                  label="Personel Tipi"
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
