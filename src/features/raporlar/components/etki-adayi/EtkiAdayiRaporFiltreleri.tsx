import { type FormEvent } from "react";
import { FormField } from "../../../../components/form/FormField";
import type { BildirimPuantajEtkiAdayState } from "../../../../types/bildirim-puantaj-etki-aday";
import type { IdOption } from "../../../../types/referans";

export type EtkiAdayiRaporFilterState = {
  ay: string;
  subeId: string;
  departmanId: string;
  personelId: string;
  state: "" | BildirimPuantajEtkiAdayState;
  conflictCode: string;
  etkiTuru: string;
  uygulamaModu: string;
  kararTuru: string;
};

type EtkiAdayiRaporFiltreleriProps = {
  filters: EtkiAdayiRaporFilterState;
  subeOptions: IdOption[];
  departmanOptions: IdOption[];
  isLoading: boolean;
  canExport: boolean;
  isExporting: boolean;
  onChange: (patch: Partial<EtkiAdayiRaporFilterState>) => void;
  onSubmit: () => void;
  onExport?: () => void;
};

const STATE_OPTIONS: Array<{ value: "" | BildirimPuantajEtkiAdayState; label: string }> = [
  { value: "", label: "Tüm durumlar" },
  { value: "HAZIR", label: "HAZIR" },
  { value: "INCELEME_GEREKLI", label: "İnceleme gerekli" },
  { value: "UYGULANDI", label: "Uygulandı" },
  { value: "YOK_SAYILDI", label: "Yok sayıldı" }
];

export function EtkiAdayiRaporFiltreleri({
  filters,
  subeOptions,
  departmanOptions,
  isLoading,
  canExport,
  isExporting,
  onChange,
  onSubmit,
  onExport
}: EtkiAdayiRaporFiltreleriProps) {
  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    onSubmit();
  }

  return (
    <form className="form-filter-panel" onSubmit={handleSubmit} data-testid="etki-adayi-rapor-filters">
      <div className="form-field-grid">
        <FormField
          label="Ay"
          name="etki-adayi-rapor-ay"
          type="month"
          value={filters.ay}
          onChange={(value) => onChange({ ay: value })}
          required
        />
        <FormField
          as="select"
          label="Şube"
          name="etki-adayi-rapor-sube"
          value={filters.subeId}
          onChange={(value) => onChange({ subeId: value })}
          placeholderOption={{ value: "", label: "Şube seçin" }}
          selectOptions={subeOptions.map((item) => ({ value: String(item.id), label: item.label }))}
          required
        />
        <FormField
          as="select"
          label="Bölüm"
          name="etki-adayi-rapor-departman"
          value={filters.departmanId}
          onChange={(value) => onChange({ departmanId: value })}
          placeholderOption={{ value: "", label: "Tüm Bölümler" }}
          selectOptions={departmanOptions.map((item) => ({ value: String(item.id), label: item.label }))}
        />
        <FormField
          label="Personel ID"
          name="etki-adayi-rapor-personel"
          type="number"
          min={1}
          value={filters.personelId}
          onChange={(value) => onChange({ personelId: value })}
        />
        <FormField
          as="select"
          label="Durum"
          name="etki-adayi-rapor-state"
          value={filters.state}
          onChange={(value) => onChange({ state: value as EtkiAdayiRaporFilterState["state"] })}
          selectOptions={STATE_OPTIONS.map((option) => ({ value: option.value, label: option.label }))}
        />
        <FormField
          label="Çakışma kodu"
          name="etki-adayi-rapor-conflict"
          value={filters.conflictCode}
          onChange={(value) => onChange({ conflictCode: value })}
        />
        <FormField
          label="Etki türü"
          name="etki-adayi-rapor-etki-turu"
          value={filters.etkiTuru}
          onChange={(value) => onChange({ etkiTuru: value })}
        />
        <FormField
          label="Uygulama modu"
          name="etki-adayi-rapor-uygulama-modu"
          value={filters.uygulamaModu}
          onChange={(value) => onChange({ uygulamaModu: value })}
        />
        <FormField
          label="Karar türü"
          name="etki-adayi-rapor-karar-turu"
          value={filters.kararTuru}
          onChange={(value) => onChange({ kararTuru: value })}
        />
      </div>

      <div className="form-actions-row">
        <button type="submit" className="universal-btn-aux" disabled={isLoading} data-testid="etki-adayi-rapor-submit">
          Raporu getir
        </button>
        {canExport ? (
          <button
            type="button"
            className="universal-btn-aux"
            disabled={isLoading || isExporting}
            data-testid="etki-adayi-rapor-export-csv"
            onClick={() => onExport?.()}
          >
            CSV indir
          </button>
        ) : null}
      </div>
    </form>
  );
}
