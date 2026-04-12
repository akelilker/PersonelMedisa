import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useIsgMakineler } from "../../../hooks/useIsgMakineler";
import {
  formatIsgBakimDurumuLabel,
  formatIsgMakineDurumLabel
} from "../../../lib/display/enum-display";
import type { IsgMakineListItem } from "../../../types/isg";

const DURUM_OPTIONS = [
  { value: "tum", label: "Tumu" },
  { value: "aktif", label: "Aktif" },
  { value: "arizali", label: "Arizali" },
  { value: "pasif", label: "Pasif" }
] as const;

function formatDate(value: string | null): string {
  if (!value) {
    return "-";
  }

  const parsed = Date.parse(value);
  if (Number.isNaN(parsed)) {
    return "-";
  }

  return new Intl.DateTimeFormat("tr-TR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric"
  }).format(new Date(parsed));
}

function formatPeriyot(value: number | null): string {
  if (!value || value <= 0) {
    return "-";
  }

  return `${value} gun`;
}

function renderGecikme(row: IsgMakineListItem): string {
  if (row.uyariDurumu !== "gecikmis" || !row.gecikmeGun) {
    return "-";
  }

  return `${row.gecikmeGun} gun`;
}

export function IsgMakinelerPage() {
  const {
    draftFilters,
    page,
    makineler,
    pagination,
    isLoading,
    errorMessage,
    submitFilters,
    clearFilters,
    setDraftSearch,
    setDraftDurum,
    setDraftTip,
    refetch,
    goPrevPage,
    goNextPage
  } = useIsgMakineler();

  return (
    <section className="isg-page">
      <div className="isg-header-row">
        <h2>Makine Envanteri</h2>
      </div>

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          <FormField
            label="Arama"
            name="isg-search"
            value={draftFilters.search}
            onChange={setDraftSearch}
            placeholder="Makine adi veya konum"
          />
          <FormField
            label="Makine Durumu"
            name="isg-durum"
            as="select"
            value={draftFilters.durum}
            onChange={(value) => setDraftDurum(value as (typeof DURUM_OPTIONS)[number]["value"])}
            selectOptions={DURUM_OPTIONS.map((option) => ({ value: option.value, label: option.label }))}
          />
          <FormField
            label="Makine Tipi"
            name="isg-tip"
            value={draftFilters.tip}
            onChange={setDraftTip}
            placeholder="Orn. Forklift"
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux" disabled={isLoading}>
            Listeyi Getir
          </button>
          <button type="button" className="universal-btn-aux" onClick={clearFilters} disabled={isLoading}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Makine listesi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void refetch()} /> : null}

      {!isLoading && !errorMessage && makineler.length === 0 ? (
        <EmptyState
          title="Makine bulunamadi"
          message="Bu filtrelerde gosterilecek makine kaydi bulunmuyor."
        />
      ) : null}

      {!isLoading && !errorMessage && makineler.length > 0 ? (
        <div className="isg-list-grid">
          {makineler.map((makine) => (
            <article key={makine.id} className="isg-list-card" data-testid={`isg-machine-${makine.id}`}>
              <div className="isg-list-card__header">
                <div>
                  <h3>{makine.ad}</h3>
                  <p>{makine.tip}</p>
                </div>
                <span className={`isg-badge isg-badge--${makine.uyariDurumu}`}>
                  {formatIsgBakimDurumuLabel(makine.uyariDurumu)}
                </span>
              </div>

              <dl className="isg-list-card__meta">
                <div>
                  <dt>Sube</dt>
                  <dd>{makine.subeAdi ?? "-"}</dd>
                </div>
                <div>
                  <dt>Konum</dt>
                  <dd>{makine.konum ?? "-"}</dd>
                </div>
                <div>
                  <dt>Makine Durumu</dt>
                  <dd>{formatIsgMakineDurumLabel(makine.durum)}</dd>
                </div>
                <div>
                  <dt>Son Bakim</dt>
                  <dd>{formatDate(makine.sonBakim)}</dd>
                </div>
                <div>
                  <dt>Sonraki Bakim</dt>
                  <dd>{formatDate(makine.sonrakiBakim)}</dd>
                </div>
                <div>
                  <dt>Bakim Periyodu</dt>
                  <dd>{formatPeriyot(makine.bakimPeriyotGun)}</dd>
                </div>
                <div>
                  <dt>Gecikme</dt>
                  <dd>{renderGecikme(makine)}</dd>
                </div>
              </dl>
            </article>
          ))}
        </div>
      ) : null}

      {!isLoading && !errorMessage && pagination ? (
        <div className="form-actions-row isg-pagination-row">
          <button
            type="button"
            className="universal-btn-aux"
            onClick={goPrevPage}
            disabled={!pagination.hasPreviousPage || page <= 1}
          >
            Onceki Sayfa
          </button>
          <span className="isg-pagination-copy">
            Sayfa {pagination.page ?? page}
            {pagination.totalPages ? ` / ${pagination.totalPages}` : ""}
          </span>
          <button
            type="button"
            className="universal-btn-aux"
            onClick={goNextPage}
            disabled={!pagination.hasNextPage}
          >
            Sonraki Sayfa
          </button>
        </div>
      ) : null}
    </section>
  );
}
