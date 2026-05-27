import type { Dispatch, ReactNode, SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
import type { SurecFormState } from "../../../hooks/useSurecler";
import type { KeyOption } from "../../../types/referans";

type PersonelOption = {
  value: string;
  label: string;
};

type AltTurFieldConfig = {
  label: string;
  options: PersonelOption[];
};

type SurecFormFieldsProps = {
  form: SurecFormState;
  setForm: Dispatch<SetStateAction<SurecFormState>>;
  surecTuruOptions: KeyOption[];
  personelOptions?: PersonelOption[];
  showPersonelField?: boolean;
  showSurecTuruField?: boolean;
  altTurField?: AltTurFieldConfig;
  useOperationControls?: boolean;
  showAltTurField?: boolean;
  showUcretliField?: boolean;
  errorMessage?: string | null;
  referenceError?: string | null;
  className?: string;
};

export const UCRETLI_SELECT_OPTIONS: Array<{ value: "evet" | "hayir"; label: string }> = [
  { value: "evet", label: "Evet" },
  { value: "hayir", label: "Hayır" }
];

type SurecChoiceOption = {
  value: string;
  label: string;
};

export function SurecChoiceGroup({
  label,
  name,
  value,
  options,
  onSelect,
  disabled = false
}: {
  label: string;
  name: string;
  value: string;
  options: SurecChoiceOption[];
  onSelect: (nextValue: string) => void;
  disabled?: boolean;
}) {
  return (
    <div className="form-section surec-choice-field">
      <span className="form-label">{label}</span>
      <div className="surec-choice-group" role="group" aria-label={label}>
        {options.map((option) => {
          const isActive = option.value === value;

          return (
            <button
              key={`${name}-${option.value}`}
              type="button"
              className={`surec-choice-btn${isActive ? " is-active" : ""}`}
              aria-pressed={isActive}
              disabled={disabled}
              onClick={() => onSelect(option.value)}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

export function SurecFormFields({
  form,
  setForm,
  surecTuruOptions,
  personelOptions = [],
  showPersonelField = true,
  showSurecTuruField = true,
  altTurField,
  useOperationControls = false,
  showAltTurField = true,
  showUcretliField = true,
  errorMessage,
  referenceError,
  className
}: SurecFormFieldsProps) {
  const renderSegmentedButtons = (
    label: string,
    name: string,
    value: string,
    options: PersonelOption[],
    onSelect: (nextValue: string) => void
  ) => (
    <SurecChoiceGroup
      label={label}
      name={name}
      value={value}
      options={options}
      onSelect={onSelect}
    />
  );

  const personelField = showPersonelField
    ? personelOptions.length > 0
      ? (
          <FormField
            as="select"
            label="Personel"
            name="surec-create-personel"
            value={form.personelId}
            onChange={(value) => setForm((prev) => ({ ...prev, personelId: value }))}
            required
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={personelOptions}
          />
        )
      : (
          <FormField
            label="Personel ID"
            name="surec-create-personel"
            type="number"
            min={1}
            value={form.personelId}
            onChange={(value) => setForm((prev) => ({ ...prev, personelId: value }))}
            required
          />
        )
    : null;

  const surecTuruFieldNode = showSurecTuruField
    ? surecTuruOptions.length > 0
      ? (
          <FormField
            as="select"
            label="Süreç Türü"
            name="surec-create-turu"
            value={form.surecTuru}
            onChange={(value) => setForm((prev) => ({ ...prev, surecTuru: value }))}
            required
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
          />
        )
      : (
          <FormField
            label="Süreç Türü"
            name="surec-create-turu-text"
            value={form.surecTuru}
            onChange={(value) => setForm((prev) => ({ ...prev, surecTuru: value }))}
            required
          />
        )
    : null;

  let altTurFieldNode: ReactNode = null;
  if (showAltTurField) {
    if (altTurField) {
      if (altTurField.options.length > 1) {
        altTurFieldNode = useOperationControls
          ? renderSegmentedButtons(
              altTurField.label,
              "surec-create-alt",
              form.altTur,
              altTurField.options,
              (value) => setForm((prev) => ({ ...prev, altTur: value }))
            )
          : (
              <FormField
                as="select"
                label={altTurField.label}
                name="surec-create-alt"
                value={form.altTur}
                onChange={(value) => setForm((prev) => ({ ...prev, altTur: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={altTurField.options}
              />
            );
      }
    } else {
      altTurFieldNode = (
        <FormField
          label="İşlem Detayı"
          name="surec-create-alt"
          value={form.altTur}
          onChange={(value) => setForm((prev) => ({ ...prev, altTur: value }))}
        />
      );
    }
  }

  const dateFields = (
    <div className={useOperationControls ? "surec-date-row" : undefined}>
      <FormField
        label="Başlangıç Tarihi"
        name="surec-create-bas"
        type="date"
        value={form.baslangicTarihi}
        onChange={(value) => setForm((prev) => ({ ...prev, baslangicTarihi: value }))}
        required
      />
      <FormField
        label="Bitiş Tarihi"
        name="surec-create-bitis"
        type="date"
        value={form.bitisTarihi}
        onChange={(value) => setForm((prev) => ({ ...prev, bitisTarihi: value }))}
        required
      />
    </div>
  );

  const ucretliFieldNode = showUcretliField
    ? useOperationControls
      ? renderSegmentedButtons(
          "Ücretli mi?",
          "surec-create-ucret",
          form.ucretliMi ? "evet" : "hayir",
          UCRETLI_SELECT_OPTIONS,
          (value) => setForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))
        )
      : (
          <FormField
            as="select"
            label="Ücretli mi?"
            name="surec-create-ucret"
            value={form.ucretliMi ? "evet" : "hayir"}
            onChange={(value) => setForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))}
            selectOptions={UCRETLI_SELECT_OPTIONS}
          />
        )
    : null;

  const aciklamaField = (
    <FormField
      as="textarea"
      label="Açıklama"
      name="surec-create-aciklama"
      value={form.aciklama}
      onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
      rows={useOperationControls ? 2 : 3}
    />
  );

  return (
    <div className={className}>
      {personelField}
      {surecTuruFieldNode}

      {useOperationControls ? (
        <div className="surec-form-layout">
          {altTurFieldNode ? (
            <div className="surec-form-layout-section surec-form-layout-section--operation">{altTurFieldNode}</div>
          ) : null}
          {ucretliFieldNode ? (
            <div className="surec-form-layout-section surec-form-layout-section--ucret">{ucretliFieldNode}</div>
          ) : null}
          <div className="surec-form-layout-section surec-form-layout-section--dates">{dateFields}</div>
          <div className="surec-form-layout-section surec-form-layout-section--notes">{aciklamaField}</div>
          {errorMessage ? <p className="surec-form-error">{errorMessage}</p> : null}
          {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
        </div>
      ) : (
        <>
          {altTurFieldNode}
          {dateFields}
          {ucretliFieldNode}
          {aciklamaField}
          {errorMessage ? <p className="surec-form-error">{errorMessage}</p> : null}
          {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
        </>
      )}
    </div>
  );
}
