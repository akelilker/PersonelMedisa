import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type Dispatch,
  type SetStateAction
} from "react";
import { FormField, type FormFieldOption } from "../../../components/form/FormField";
import { mapUcretTipiSelectOptions } from "../../../lib/display/ucret-tipi-display";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import type { CreatePersonelFormState } from "../../../hooks/usePersoneller";
import type { IdOption } from "../../../types/referans";

type PersonelCreateFieldsProps = {
  form: CreatePersonelFormState;
  setForm: Dispatch<SetStateAction<CreatePersonelFormState>>;
  onDepartmanChange?: (value: string) => void;
  onBagliAmirChange?: (value: string) => void;
  bagliAmirInfoMessage?: string | null;
  bagliAmirSubeWarning?: string | null;
  bagliAmirDepartmanWarning?: string | null;
  refs: PersonelReferenceBundle;
  subeOptions?: IdOption[];
  subeLoadError?: string | null;
  createErrorMessage?: string | null;
  fieldErrors?: Partial<Record<"tcKimlikNo" | "subeId", string>>;
  onFieldErrorClear?: (field: "tcKimlikNo" | "subeId") => void;
  referenceError?: string | null;
  className?: string;
};

type PersonelCreateSelectProps = {
  label: string;
  name: string;
  value: string;
  options: FormFieldOption[];
  onChange: (value: string) => void;
  isOpen: boolean;
  onOpenChange: (isOpen: boolean) => void;
  placeholderOption?: FormFieldOption;
  required?: boolean;
  disabled?: boolean;
};

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

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

function refMissingNote(label: string, blocking: boolean) {
  return (
    <p className="personel-create-error" role="alert">
      {blocking
        ? `${label} listesi yüklenemedi. Bu alan zorunlu; referanslar gelene kadar kayıt oluşturulamaz.`
        : `${label} referansı yüklenemedi; bu alan şimdilik seçilemez.`}
    </p>
  );
}

function PersonelCreateSelect({
  label,
  name,
  value,
  options,
  onChange,
  isOpen,
  onOpenChange,
  placeholderOption,
  required = false,
  disabled = false
}: PersonelCreateSelectProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const labelId = `${name}-label`;
  const panelId = `${name}-panel`;
  const allOptions = useMemo(
    () => (placeholderOption ? [placeholderOption, ...options] : options),
    [options, placeholderOption]
  );
  const selectedOption = allOptions.find((option) => option.value === value) ?? placeholderOption ?? null;
  const isPlaceholderSelected = placeholderOption ? value === placeholderOption.value : false;

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    function handleMouseDown(event: MouseEvent) {
      if (!rootRef.current?.contains(event.target as Node)) {
        onOpenChange(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onOpenChange(false);
      }
    }

    document.addEventListener("mousedown", handleMouseDown);
    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("mousedown", handleMouseDown);
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [isOpen, onOpenChange]);

  return (
    <div className="form-section personel-create-select" ref={rootRef}>
      <label className="form-label" id={labelId}>
        {label}
      </label>
      <button
        type="button"
        id={name}
        className={`form-input personel-create-select-trigger${isOpen ? " is-open" : ""}${
          isPlaceholderSelected ? " is-placeholder" : ""
        }`}
        role="combobox"
        aria-labelledby={labelId}
        aria-expanded={isOpen}
        aria-controls={panelId}
        aria-required={required}
        disabled={disabled}
        onClick={() => onOpenChange(!isOpen)}
      >
        <span>{selectedOption?.label ?? "Seçiniz"}</span>
        <span className="personel-create-select-caret" aria-hidden="true">
          v
        </span>
      </button>

      {isOpen ? (
        <div className="personel-create-select-panel" id={panelId} role="listbox" aria-labelledby={labelId}>
          {allOptions.map((option) => {
            const isSelected = value === option.value;
            const isPlaceholder = placeholderOption ? option.value === placeholderOption.value : false;

            return (
              <button
                key={`${name}-${option.value || "empty"}`}
                type="button"
                role="option"
                aria-selected={isSelected}
                className={`personel-create-select-option${isSelected ? " is-active" : ""}${
                  isPlaceholder ? " is-placeholder" : ""
                }`}
                onClick={() => {
                  onChange(option.value);
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

export function PersonelCreateFields({
  form,
  setForm,
  onDepartmanChange,
  onBagliAmirChange,
  bagliAmirInfoMessage,
  bagliAmirSubeWarning,
  bagliAmirDepartmanWarning,
  refs,
  subeOptions = [],
  subeLoadError,
  createErrorMessage,
  fieldErrors,
  onFieldErrorClear,
  referenceError,
  className
}: PersonelCreateFieldsProps) {
  const [openSelectName, setOpenSelectName] = useState<string | null>(null);
  const tcKimlikNoFieldError = fieldErrors?.tcKimlikNo;
  const subeIdFieldError = fieldErrors?.subeId;

  function setSelectOpen(name: string, isOpen: boolean) {
    setOpenSelectName(isOpen ? name : null);
  }

  useLayoutEffect(() => {
    if (tcKimlikNoFieldError) {
      const input = document.getElementById("create-tc");
      if (!(input instanceof HTMLInputElement)) {
        return;
      }

      try {
        input.focus({ preventScroll: true });
        input.scrollIntoView({ block: "nearest", behavior: "smooth" });
      } catch {
        /* Focus/scroll desteklenmiyorsa sessizce devam et. */
      }
      return;
    }

    if (!subeIdFieldError) {
      return;
    }

    const trigger = document.getElementById("create-sube");
    if (!(trigger instanceof HTMLElement)) {
      return;
    }

    try {
      trigger.focus({ preventScroll: true });
      trigger.scrollIntoView({ block: "nearest", behavior: "smooth" });
    } catch {
      /* Focus/scroll desteklenmiyorsa sessizce devam et. */
    }
  }, [tcKimlikNoFieldError, subeIdFieldError]);

  return (
    <div className={className}>
      <div className="personel-form-columns">
        <div className="personel-form-column">
          <FormField
            label="T.C. Kimlik No"
            name="create-tc"
            value={form.tcKimlikNo}
            onChange={(value) => {
              setForm((prev) => ({ ...prev, tcKimlikNo: value }));
              onFieldErrorClear?.("tcKimlikNo");
            }}
            placeholder="Örn. 12345678122"
            required
          />
          {tcKimlikNoFieldError ? (
            <p className="personel-create-error" role="alert">
              {tcKimlikNoFieldError}
            </p>
          ) : null}
          <FormField
            label="Ad"
            name="create-ad"
            value={form.ad}
            onChange={(value) => setForm((prev) => ({ ...prev, ad: value }))}
            placeholder="Örn. İlker"
            required
          />
          <FormField
            label="Soyad"
            name="create-soyad"
            value={form.soyad}
            onChange={(value) => setForm((prev) => ({ ...prev, soyad: value }))}
            placeholder="Örn. AKEL"
            required
          />
          <FormField
            label="Doğum Tarihi"
            name="create-dogum"
            type="date"
            value={form.dogumTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, dogumTarihi: value }))}
            required
          />
          <FormField
            label="Telefon"
            name="create-telefon"
            type="tel"
            value={form.telefon}
            onChange={(value) => setForm((prev) => ({ ...prev, telefon: value }))}
            placeholder="Örn. 0532 123 45 67"
            required
          />
          <FormField
            label="Acil Durum Kişisi"
            name="create-acil-kisi"
            value={form.acilDurumKisi}
            onChange={(value) => setForm((prev) => ({ ...prev, acilDurumKisi: value }))}
            placeholder="Örn. Serhan Köse"
            required
          />
          <FormField
            label="Acil Durum Telefon"
            name="create-acil-tel"
            type="tel"
            value={form.acilDurumTelefon}
            onChange={(value) => setForm((prev) => ({ ...prev, acilDurumTelefon: value }))}
            placeholder="Örn. 0532 123 45 67"
            required
          />
          <FormField
            label="Doğum Yeri"
            name="create-dogum-yeri"
            value={form.dogumYeri}
            onChange={(value) => setForm((prev) => ({ ...prev, dogumYeri: value }))}
            placeholder="Örn. İstanbul"
          />
          <PersonelCreateSelect
            label="Kan Grubu"
            name="create-kan"
            value={form.kanGrubu}
            onChange={(value) => setForm((prev) => ({ ...prev, kanGrubu: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            options={kanGrubuOptions}
            isOpen={openSelectName === "create-kan"}
            onOpenChange={(isOpen) => setSelectOpen("create-kan", isOpen)}
          />
        </div>

        <div className="personel-form-column">
          <FormField
            label="Sicil No"
            name="create-sicil"
            value={form.sicilNo}
            onChange={(value) => setForm((prev) => ({ ...prev, sicilNo: value }))}
            placeholder="Örn. MED-001"
            required
          />
          <FormField
            label="İşe Giriş Tarihi"
            name="create-ise-giris"
            type="date"
            value={form.iseGirisTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, iseGirisTarihi: value }))}
            required
          />
          {subeLoadError ? (
            <p className="personel-create-error" role="alert">
              {subeLoadError}
            </p>
          ) : subeOptions.length > 0 ? (
            <>
              <PersonelCreateSelect
                label="Şube"
                name="create-sube"
                value={form.subeId}
                onChange={(value) => {
                  setForm((prev) => ({ ...prev, subeId: value }));
                  onFieldErrorClear?.("subeId");
                }}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={toSelectOptions(subeOptions)}
                isOpen={openSelectName === "create-sube"}
                onOpenChange={(isOpen) => setSelectOpen("create-sube", isOpen)}
              />
              {subeIdFieldError ? (
                <p className="personel-create-error" role="alert">
                  {subeIdFieldError}
                </p>
              ) : null}
            </>
          ) : (
            refMissingNote("Şube", true)
          )}
          {refs.bagliAmirOptions.length > 0 ? (
            <>
              <PersonelCreateSelect
                label="Bağlı Amir"
                name="create-bagli-amir"
                value={form.bagliAmirId}
                onChange={
                  onBagliAmirChange ??
                  ((value) => setForm((prev) => ({ ...prev, bagliAmirId: value })))
                }
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={toSelectOptions(refs.bagliAmirOptions)}
                isOpen={openSelectName === "create-bagli-amir"}
                onOpenChange={(isOpen) => setSelectOpen("create-bagli-amir", isOpen)}
              />
              {bagliAmirInfoMessage ? (
                <p className="personel-form-note personel-form-note--info">{bagliAmirInfoMessage}</p>
              ) : null}
              {bagliAmirSubeWarning ? (
                <p className="personel-form-note personel-form-note--warning">{bagliAmirSubeWarning}</p>
              ) : null}
            </>
          ) : (
            refMissingNote("Bağlı amir", false)
          )}
          {refs.departmanOptions.length > 0 ? (
            <>
              <PersonelCreateSelect
                label="Bölüm"
                name="create-departman"
                value={form.departmanId}
                onChange={
                  onDepartmanChange ??
                  ((value) => setForm((prev) => ({ ...prev, departmanId: value })))
                }
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={toSelectOptions(refs.departmanOptions)}
                isOpen={openSelectName === "create-departman"}
                onOpenChange={(isOpen) => setSelectOpen("create-departman", isOpen)}
              />
              {bagliAmirDepartmanWarning ? (
                <p className="personel-form-note personel-form-note--warning">
                  {bagliAmirDepartmanWarning}
                </p>
              ) : null}
            </>
          ) : (
            refMissingNote("Bölüm", true)
          )}
          {refs.gorevOptions.length > 0 ? (
            <PersonelCreateSelect
              label="Görev / Unvan"
              name="create-gorev"
              value={form.gorevId}
              onChange={(value) => setForm((prev) => ({ ...prev, gorevId: value }))}
              required
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(refs.gorevOptions)}
              isOpen={openSelectName === "create-gorev"}
              onOpenChange={(isOpen) => setSelectOpen("create-gorev", isOpen)}
            />
          ) : (
            refMissingNote("Görev / Unvan", true)
          )}
          {refs.personelTipiOptions.length > 0 ? (
            <PersonelCreateSelect
              label="Personel Tipi"
              name="create-personel-tipi"
              value={form.personelTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, personelTipiId: value }))}
              required
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(refs.personelTipiOptions)}
              isOpen={openSelectName === "create-personel-tipi"}
              onOpenChange={(isOpen) => setSelectOpen("create-personel-tipi", isOpen)}
            />
          ) : (
            refMissingNote("Personel Tipi", true)
          )}
          {refs.ucretTipiOptions.length > 0 ? (
            <PersonelCreateSelect
              label="Ücret Tipi"
              name="create-ucret-tipi"
              value={form.ucretTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, ucretTipiId: value }))}
              options={mapUcretTipiSelectOptions(refs.ucretTipiOptions)}
              isOpen={openSelectName === "create-ucret-tipi"}
              onOpenChange={(isOpen) => setSelectOpen("create-ucret-tipi", isOpen)}
            />
          ) : (
            refMissingNote("Ücret Tipi", false)
          )}
          <FormField
            label="Maaş Tutarı"
            name="create-maas"
            type="number"
            min={0}
            step="0.01"
            value={form.maasTutari}
            onChange={(value) => setForm((prev) => ({ ...prev, maasTutari: value }))}
            placeholder="Örn. 35000"
          />
        </div>
      </div>

      {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
      {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}
    </div>
  );
}
