import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersoneller } from "../../../hooks/usePersoneller";
import { formatAktifDurumLabel, formatCalisanKapsamiLabel, CALISAN_KAPSAMI_SELECT_OPTIONS } from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import { PersonelImportDryRunModal } from "../components/PersonelImportDryRunModal";
import { PersonelImportHistoryModal } from "../components/PersonelImportHistoryModal";
import { getPersonelMissingFields } from "../personel-missing-info";

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

function formatPersonelName(personel: Pick<Personel, "ad" | "soyad">) {
  return [personel.ad, personel.soyad].map((part) => String(part ?? "").trim()).filter(Boolean).join(" ");
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
    submitFilters,
    clearFilters,
    setDraftSearch,
    setDraftAktiflik,
    setDraftDepartmanId,
    setDraftPersonelTipiId,
    setDraftCalisanKapsami,
    setPage
  } = usePersoneller();

  const { hasPermission } = useRoleAccess();
  const canOpenDetail = hasPermission("personeller.detail.view");
  const canCreatePersonel = hasPermission("personeller.create");
  const canApplyPersonelImport = hasPermission("personeller.import.apply");
  const canViewArsiv = hasPermission("arsiv.view");
  const navigate = useNavigate();
  const [searchExpanded, setSearchExpanded] = useState(false);
  const [filterExpanded, setFilterExpanded] = useState(false);
  const [viewMode, setViewMode] = useState<"list" | "grid">("grid");
  const [importModalOpen, setImportModalOpen] = useState(false);
  const [historyModalOpen, setHistoryModalOpen] = useState(false);

  const { draft } = listQuery;
  const isArchiveMode = canViewArsiv && draft.aktiflik === "pasif";
  const page = listQuery.page;
  const departmanFilterOptions = toSelectOptions(refs.departmanOptions);
  const personelTipiFilterOptions = toSelectOptions(refs.personelTipiOptions);

  return (
    <section className="personeller-page" aria-labelledby="personeller-page-heading">
      <h2 id="personeller-page-heading" className="personeller-sr-only">
        Personeller
      </h2>

      {isArchiveMode ? (
        <p className="personeller-archive-banner" data-testid="personeller-arsiv-banner" role="status">
          Arşiv — Medisa saklama politikası
        </p>
      ) : null}

      <div className="personeller-toolbar">
        <div className="personeller-toolbar-main">
          <div className="personeller-toolbar-left">
            <Link
              to="/"
              className="personeller-icon-btn personeller-toolbar-back-link"
              aria-label="Ana panele dön"
            >
              <IconBack />
            </Link>
            <button
              type="button"
              className="personeller-icon-btn"
              aria-expanded={searchExpanded}
              aria-controls="personeller-filter-form"
              aria-label={searchExpanded ? "Aramayı kapat" : "Arama aç"}
              onClick={() => setSearchExpanded((open) => !open)}
            >
              <IconSearch />
            </button>
            <button
              type="button"
              className="personeller-icon-btn"
              aria-expanded={filterExpanded}
              aria-controls="personeller-filter-form"
              aria-label={filterExpanded ? "Detaylı filtreyi kapat" : "Detaylı filtre aç"}
              onClick={() => setFilterExpanded((open) => !open)}
            >
              <IconFilter />
            </button>
          </div>
          <div className="personeller-toolbar-right">
            {canCreatePersonel ? (
              <button
                type="button"
                className="universal-btn-aux personeller-import-action"
                data-testid="personeller-import-dry-run-open"
                onClick={() => setImportModalOpen(true)}
              >
                Toplu Personel Hazırlama
              </button>
            ) : null}
            {canApplyPersonelImport ? (
              <button
                type="button"
                className="universal-btn-aux personeller-import-action"
                data-testid="personeller-import-history-open"
                onClick={() => setHistoryModalOpen(true)}
              >
                Import Geçmişi
              </button>
            ) : null}
            <button
              type="button"
              className="personeller-icon-btn"
              aria-label={
                viewMode === "grid" ? "Liste görünümüne geç" : "Kart görünümüne geç"
              }
              onClick={() => setViewMode((mode) => (mode === "grid" ? "list" : "grid"))}
            >
              {viewMode === "grid" ? <IconList /> : <IconGrid />}
            </button>
          </div>
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
                    label="Departman"
                    name="personel-filter-departman"
                    value={draft.departmanId}
                    onChange={setDraftDepartmanId}
                    placeholderOption={{ value: "", label: "Tümü" }}
                    selectOptions={departmanFilterOptions}
                  />
                ) : (
                  <FormField
                    label="Departman"
                    name="personel-filter-departman-num"
                    type="number"
                    min={1}
                    placeholder="Tümü"
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
                    placeholderOption={{ value: "", label: "Tümü" }}
                    selectOptions={personelTipiFilterOptions}
                  />
                ) : (
                  <FormField
                    label="Personel tipi"
                    name="personel-filter-personel-tipi-num"
                    type="number"
                    min={1}
                    placeholder="Tümü"
                    value={draft.personelTipiId}
                    onChange={setDraftPersonelTipiId}
                  />
                )}
                <FormField
                  as="select"
                  label="Çalışan Kapsamı"
                  name="personel-filter-calisan-kapsami"
                  value={draft.calisanKapsami}
                  onChange={(value) =>
                    setDraftCalisanKapsami(value as "" | "IC_PERSONEL" | "DIS_KAYNAK")
                  }
                  selectOptions={CALISAN_KAPSAMI_SELECT_OPTIONS}
                  placeholderOption={{ value: "", label: "Tümü" }}
                />
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
                    {canViewArsiv ? (
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
                        <span>Arşiv</span>
                      </label>
                    ) : null}
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

      {isLoading ? <LoadingState label="Personel verileri yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && personeller.length === 0 ? (
        <EmptyState
          title="Personel kaydı bulunamadı"
          message="Filtre veya kaynak veri durumunu kontrol et."
        />
      ) : null}

      {!isLoading && !errorMessage && personeller.length > 0 && viewMode === "list" ? (
        <div className="personeller-table-wrap">
          <table className="personeller-table">
            <thead>
              <tr>
                <th scope="col">Ad Soyad</th>
                <th scope="col">Departman</th>
                <th scope="col">Unvan</th>
                <th scope="col">Durum</th>
                <th scope="col">Telefon</th>
                <th scope="col" className="personeller-table-col-actions">
                  Hızlı
                </th>
              </tr>
            </thead>
            <tbody>
              {personeller.map((personel: Personel) => {
                const personelCallHref = buildTelHref(personel.telefon);
                const emergencyCallHref = buildTelHref(personel.acil_durum_telefon);
                const detailTo = `/personeller/${personel.id}`;
                const personelName = formatPersonelName(personel);
                const missingFieldCount = getPersonelMissingFields(personel).length;
                const previewLabel = `${personelName} kişisinin kartını aç`;

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
                          {personelName}
                        </Link>
                      ) : (
                        personelName
                      )}
                      {personel.calisan_kapsami === "DIS_KAYNAK" ? (
                        <span className="personeller-status-badge">
                          {formatCalisanKapsamiLabel("DIS_KAYNAK")}
                        </span>
                      ) : null}
                      {missingFieldCount > 0 ? (
                        <span
                          className="personeller-missing-badge"
                          title={`${missingFieldCount} kritik bilgi eksik`}
                          data-testid={`personel-eksik-bilgi-${personel.id}`}
                        >
                          Eksik Bilgi
                        </span>
                      ) : null}
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
              const personelName = formatPersonelName(personel);
              const missingFieldCount = getPersonelMissingFields(personel).length;
              const previewLabel = `${personelName} kişisinin kartını aç`;

              const previewInner = (
                <div className="personeller-item-content personeller-item-content--grid">
                  <span className="personeller-card-title">{personelName}</span>
                  {personel.calisan_kapsami === "DIS_KAYNAK" ? (
                    <span className="personeller-status-badge">
                      {formatCalisanKapsamiLabel("DIS_KAYNAK")}
                    </span>
                  ) : null}
                  {missingFieldCount > 0 ? (
                    <span
                      className="personeller-missing-badge"
                      title={`${missingFieldCount} kritik bilgi eksik`}
                      data-testid={`personel-eksik-bilgi-${personel.id}`}
                    >
                      Eksik Bilgi
                    </span>
                  ) : null}
                  <span className="personeller-card-sub">{personelGridSubtitle(personel)}</span>
                  <span className="personeller-card-muted">{personelGridMutedLine(personel)}</span>
                </div>
              );

              return (
                <li
                  key={personel.id}
                  className={`personeller-item personeller-item--grid ${
                    missingFieldCount > 0 ? "personeller-item--has-missing" : ""
                  }`}
                >
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

      <PersonelImportDryRunModal
        open={importModalOpen}
        onClose={() => setImportModalOpen(false)}
        canApply={canApplyPersonelImport}
        onApplied={() => {
          void refetch();
        }}
      />
      <PersonelImportHistoryModal
        open={historyModalOpen}
        onClose={() => setHistoryModalOpen(false)}
      />
    </section>
  );
}