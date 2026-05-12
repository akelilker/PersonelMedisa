import type { Dispatch, FormEvent, SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
import type { PersonelZimmetFormState } from "../../../hooks/usePersoneller";
import { ZIMMET_TESLIM_DURUMU_OPTIONS, ZIMMET_URUN_TURU_OPTIONS } from "../../../types/zimmet";

export type PersonelZimmetCreateFormProps = {
  formId: string;
  zimmetForm: PersonelZimmetFormState;
  setZimmetForm: Dispatch<SetStateAction<PersonelZimmetFormState>>;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  zimmetCreateErrorMessage: string | null;
};

export function PersonelZimmetCreateForm({
  formId,
  zimmetForm,
  setZimmetForm,
  onSubmit,
  zimmetCreateErrorMessage
}: PersonelZimmetCreateFormProps) {
  return (
    <form id={formId} className="personel-zimmet-form-grid" onSubmit={onSubmit}>
      <FormField
        as="select"
        label="Ürün Türü"
        name="personel-zimmet-urun-turu"
        value={zimmetForm.urunTuru}
        onChange={(value) => setZimmetForm((prev) => ({ ...prev, urunTuru: value }))}
        required
        placeholderOption={{ value: "", label: "Seçiniz" }}
        selectOptions={[...ZIMMET_URUN_TURU_OPTIONS]}
      />
      <FormField
        label="Teslim Tarihi"
        name="personel-zimmet-teslim-tarihi"
        type="date"
        value={zimmetForm.teslimTarihi}
        onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimTarihi: value }))}
        required
      />
      <FormField
        label="Teslim Eden"
        name="personel-zimmet-teslim-eden"
        value={zimmetForm.teslimEden}
        onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimEden: value }))}
        required
        placeholder="Bağlı amir veya İK görevlisi"
      />
      <FormField
        as="select"
        label="Teslim Durumu"
        name="personel-zimmet-teslim-durumu"
        value={zimmetForm.teslimDurumu}
        onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimDurumu: value }))}
        required
        selectOptions={[...ZIMMET_TESLIM_DURUMU_OPTIONS]}
      />
      <FormField
        as="textarea"
        label="Seri No / Açıklama"
        name="personel-zimmet-aciklama"
        value={zimmetForm.aciklama}
        onChange={(value) => setZimmetForm((prev) => ({ ...prev, aciklama: value }))}
        rows={4}
      />
      {zimmetCreateErrorMessage ? <p className="personel-create-error">{zimmetCreateErrorMessage}</p> : null}
    </form>
  );
}
