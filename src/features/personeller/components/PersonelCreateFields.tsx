import type { Dispatch, SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import type { CreatePersonelFormState } from "../../../hooks/usePersoneller";
import type { IdOption } from "../../../types/referans";

type PersonelCreateFieldsProps = {
  form: CreatePersonelFormState;
  setForm: Dispatch<SetStateAction<CreatePersonelFormState>>;
  refs: PersonelReferenceBundle;
  createErrorMessage?: string | null;
  referenceError?: string | null;
  className?: string;
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

export function PersonelCreateFields({
  form,
  setForm,
  refs,
  createErrorMessage,
  referenceError,
  className
}: PersonelCreateFieldsProps) {
  return (
    <div className={className}>
      <div className="personel-form-columns">
        <div className="personel-form-column">
          <div className="personel-form-column-heading">Kimlik ve İletişim</div>
          <FormField
            label="T.C. Kimlik No"
            name="create-tc"
            value={form.tcKimlikNo}
            onChange={(value) => setForm((prev) => ({ ...prev, tcKimlikNo: value }))}
            required
          />
          <FormField
            label="Ad"
            name="create-ad"
            value={form.ad}
            onChange={(value) => setForm((prev) => ({ ...prev, ad: value }))}
            required
          />
          <FormField
            label="Soyad"
            name="create-soyad"
            value={form.soyad}
            onChange={(value) => setForm((prev) => ({ ...prev, soyad: value }))}
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
            required
          />
          <FormField
            label="Acil Durum Kişisi"
            name="create-acil-kisi"
            value={form.acilDurumKisi}
            onChange={(value) => setForm((prev) => ({ ...prev, acilDurumKisi: value }))}
            required
          />
          <FormField
            label="Acil Durum Telefon"
            name="create-acil-tel"
            type="tel"
            value={form.acilDurumTelefon}
            onChange={(value) => setForm((prev) => ({ ...prev, acilDurumTelefon: value }))}
            required
          />
          <FormField
            label="Doğum Yeri"
            name="create-dogum-yeri"
            value={form.dogumYeri}
            onChange={(value) => setForm((prev) => ({ ...prev, dogumYeri: value }))}
          />
          <FormField
            as="select"
            label="Kan Grubu"
            name="create-kan"
            value={form.kanGrubu}
            onChange={(value) => setForm((prev) => ({ ...prev, kanGrubu: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={kanGrubuOptions}
          />
        </div>

        <div className="personel-form-column">
          <div className="personel-form-column-heading">İş ve Atama</div>
          <FormField
            label="Sicil No"
            name="create-sicil"
            value={form.sicilNo}
            onChange={(value) => setForm((prev) => ({ ...prev, sicilNo: value }))}
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
          {refs.departmanOptions.length > 0 ? (
            <FormField
              as="select"
              label="Bölüm"
              name="create-departman"
              value={form.departmanId}
              onChange={(value) => setForm((prev) => ({ ...prev, departmanId: value }))}
              required
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={toSelectOptions(refs.departmanOptions)}
            />
          ) : (
            <FormField
              label="Bölüm"
              name="create-departman-num"
              type="number"
              min={1}
              value={form.departmanId}
              onChange={(value) => setForm((prev) => ({ ...prev, departmanId: value }))}
              required
            />
          )}
          {refs.gorevOptions.length > 0 ? (
            <FormField
              as="select"
              label="Görev"
              name="create-gorev"
              value={form.gorevId}
              onChange={(value) => setForm((prev) => ({ ...prev, gorevId: value }))}
              required
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={toSelectOptions(refs.gorevOptions)}
            />
          ) : (
            <FormField
              label="Görev"
              name="create-gorev-num"
              type="number"
              min={1}
              value={form.gorevId}
              onChange={(value) => setForm((prev) => ({ ...prev, gorevId: value }))}
              required
            />
          )}
          {refs.bagliAmirOptions.length > 0 ? (
            <FormField
              as="select"
              label="Bağlı Amir"
              name="create-bagli-amir"
              value={form.bagliAmirId}
              onChange={(value) => setForm((prev) => ({ ...prev, bagliAmirId: value }))}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={toSelectOptions(refs.bagliAmirOptions)}
            />
          ) : (
            <FormField
              label="Bağlı Amir"
              name="create-bagli-amir-num"
              type="number"
              min={1}
              value={form.bagliAmirId}
              onChange={(value) => setForm((prev) => ({ ...prev, bagliAmirId: value }))}
            />
          )}
          {refs.personelTipiOptions.length > 0 ? (
            <FormField
              as="select"
              label="Personel Tipi"
              name="create-personel-tipi"
              value={form.personelTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, personelTipiId: value }))}
              required
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={toSelectOptions(refs.personelTipiOptions)}
            />
          ) : (
            <FormField
              label="Personel Tipi"
              name="create-personel-tipi-num"
              type="number"
              min={1}
              value={form.personelTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, personelTipiId: value }))}
              required
            />
          )}
        </div>
      </div>

      {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
      {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}
    </div>
  );
}
