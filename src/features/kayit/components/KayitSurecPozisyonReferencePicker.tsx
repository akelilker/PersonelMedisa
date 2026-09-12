import { useMemo } from "react";
import { AppSelectField } from "../../../components/form/AppSelect";
import type { IdOption } from "../../../types/referans";

type KayitSurecPozisyonReferencePickerProps = {
  label: string;
  name: string;
  value: string;
  options: IdOption[];
  isOpen: boolean;
  required?: boolean;
  disabled?: boolean;
  onChange: (value: string) => void;
  onOpenChange: (isOpen: boolean) => void;
};

/**
 * Kayıt ve Süreç → Pozisyon referans alanları.
 * Sunum tamamen kanonik AppSelect owner'ına devredilmiştir; burada yalnız
 * IdOption → select option eşlemesi ve açık durum köprüsü kalır.
 */
export function KayitSurecPozisyonReferencePicker({
  label,
  name,
  value,
  options,
  isOpen,
  required = false,
  disabled = false,
  onChange,
  onOpenChange
}: KayitSurecPozisyonReferencePickerProps) {
  const selectOptions = useMemo(
    () => options.map((option) => ({ value: String(option.id), label: option.label })),
    [options]
  );

  return (
    <AppSelectField
      label={label}
      name={name}
      value={value}
      options={selectOptions}
      placeholderOption={{ value: "", label: "Seçiniz" }}
      required={required}
      disabled={disabled}
      open={isOpen}
      onOpenChange={onOpenChange}
      onChange={onChange}
    />
  );
}
