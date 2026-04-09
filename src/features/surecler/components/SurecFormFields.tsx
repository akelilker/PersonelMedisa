import type { Dispatch, SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
import type { SurecFormState } from "../../../hooks/useSurecler";
import type { KeyOption } from "../../../types/referans";

type PersonelOption = {
  value: string;
  label: string;
};

type SurecFormFieldsProps = {
  form: SurecFormState;
  setForm: Dispatch<SetStateAction<SurecFormState>>;
  surecTuruOptions: KeyOption[];
  personelOptions?: PersonelOption[];
  errorMessage?: string | null;
  referenceError?: string | null;
  className?: string;
};

const UCRETLI_SELECT_OPTIONS = [
  { value: "evet", label: "Evet" },
  { value: "hayir", label: "Hayır" }
];

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

export function SurecFormFields({
  form,
  setForm,
  surecTuruOptions,
  personelOptions = [],
  errorMessage,
  referenceError,
  className
}: SurecFormFieldsProps) {
  return (
    <div className={className}>
      {personelOptions.length > 0 ? (
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
      ) : (
        <FormField
          label="Personel ID"
          name="surec-create-personel"
          type="number"
          min={1}
          value={form.personelId}
          onChange={(value) => setForm((prev) => ({ ...prev, personelId: value }))}
          required
        />
      )}

      {surecTuruOptions.length > 0 ? (
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
      ) : (
        <FormField
          label="Süreç Türü"
          name="surec-create-turu-text"
          value={form.surecTuru}
          onChange={(value) => setForm((prev) => ({ ...prev, surecTuru: value }))}
          required
        />
      )}

      <FormField
        label="Alt Tür"
        name="surec-create-alt"
        value={form.altTur}
        onChange={(value) => setForm((prev) => ({ ...prev, altTur: value }))}
      />
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
      <FormField
        as="select"
        label="Ücretli mi?"
        name="surec-create-ucret"
        value={form.ucretliMi ? "evet" : "hayir"}
        onChange={(value) => setForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))}
        selectOptions={UCRETLI_SELECT_OPTIONS}
      />
      <FormField
        as="textarea"
        label="Açıklama"
        name="surec-create-aciklama"
        value={form.aciklama}
        onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
        rows={3}
      />

      {errorMessage ? <p className="surec-form-error">{errorMessage}</p> : null}
      {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
    </div>
  );
}
