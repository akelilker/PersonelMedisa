import { type FormEvent } from "react";
import { FormField } from "../../../../components/form/FormField";
import type { IdOption } from "../../../../types/referans";
import type { DonemKapanisFilterState } from "../../../../hooks/useDonemKapanisPreflight";

type DonemKapanisFiltreleriProps = {
  filters: DonemKapanisFilterState;
  subeOptions: IdOption[];
  departmanOptions: IdOption[];
  isLoading: boolean;
  canExport: boolean;
  isExporting: boolean;
  onChange: (patch: Partial<DonemKapanisFilterState>) => void;
  onSubmit: () => void;
  onExport?: () => void;
};

export function DonemKapanisFiltreleri({
  filters,
  subeOptions,
  departmanOptions,
  isLoading,
  canExport,
  isExporting,
  onChange,
  onSubmit,
  onExport
}: DonemKapanisFiltreleriProps) {
  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    onSubmit();
  }

  return (
    <form className="form-filter-panel" onSubmit={handleSubmit} data-testid="donem-kapanis-filters">
      <div className="form-field-grid">
        <FormField
          label="Ay"
          name="donem-kapanis-ay"
          type="month"
          value={filters.ay}
          onChange={(value) => onChange({ ay: value })}
          required
        />
        <FormField
          as="select"
          label="Şube"
          name="donem-kapanis-sube"
          value={filters.subeId}
          onChange={(value) => onChange({ subeId: value })}
          placeholderOption={{ value: "", label: "Şube seçin" }}
          selectOptions={subeOptions.map((item) => ({ value: String(item.id), label: item.label }))}
          required
        />
        <FormField
          as="select"
          label="Bölüm"
          name="donem-kapanis-departman"
          value={filters.departmanId}
          onChange={(value) => onChange({ departmanId: value })}
          placeholderOption={{ value: "", label: "Tüm Bölümler" }}
          selectOptions={departmanOptions.map((item) => ({ value: String(item.id), label: item.label }))}
        />
        <FormField
          label="Personel ID"
          name="donem-kapanis-personel"
          type="number"
          min={1}
          value={filters.personelId}
          onChange={(value) => onChange({ personelId: value })}
        />
      </div>

      <div className="form-actions-row">
        <button type="submit" className="universal-btn-aux" disabled={isLoading} data-testid="donem-kapanis-submit">
          Ön kontrolü getir
        </button>
        {canExport ? (
          <button
            type="button"
            className="universal-btn-aux"
            disabled={isLoading || isExporting}
            data-testid="donem-kapanis-export-csv"
            onClick={() => onExport?.()}
          >
            CSV indir
          </button>
        ) : null}
      </div>
    </form>
  );
}
