import type { IdOption } from "../../../types/referans";
import { optionLabel } from "../kayit-surec-utils";

type KayitSurecPozisyonReferencePickerProps = {
  label: string;
  name: string;
  value: string;
  options: IdOption[];
  isOpen: boolean;
  required?: boolean;
  onChange: (value: string) => void;
  onOpenChange: (isOpen: boolean) => void;
};

export function KayitSurecPozisyonReferencePicker({
  label,
  name,
  value,
  options,
  isOpen,
  required = false,
  onChange,
  onOpenChange
}: KayitSurecPozisyonReferencePickerProps) {
  const selectedLabel = optionLabel(options, value, "Seçiniz");

  return (
    <div className="form-section surec-position-picker">
      <label className="form-label" id={`${name}-label`}>
        {label}
      </label>
      <button
        type="button"
        className="form-input surec-position-picker-trigger"
        role="combobox"
        aria-labelledby={`${name}-label`}
        aria-expanded={isOpen}
        aria-controls={`${name}-panel`}
        onClick={() => onOpenChange(!isOpen)}
      >
        <span>{selectedLabel === "-" ? "Seçiniz" : selectedLabel}</span>
        <span aria-hidden="true">⌄</span>
      </button>

      {isOpen ? (
        <div className="surec-position-picker-panel" id={`${name}-panel`}>
          {!required ? (
            <button
              type="button"
              className={`surec-position-picker-option${value === "" ? " is-active" : ""}`}
              onClick={() => {
                onChange("");
                onOpenChange(false);
              }}
            >
              Seçiniz
            </button>
          ) : null}
          {options.map((option) => {
            const optionValue = String(option.id);
            const isActive = value === optionValue;

            return (
              <button
                key={`${name}-${option.id}`}
                type="button"
                className={`surec-position-picker-option${isActive ? " is-active" : ""}`}
                onClick={() => {
                  onChange(optionValue);
                  onOpenChange(false);
                }}
              >
                {option.label}
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}
