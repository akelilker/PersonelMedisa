import type { Dispatch, SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
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

function refMissingNote(label: string, blocking: boolean) {
  return (
    <p className="personel-create-error" role="alert">
      {blocking
        ? `${label} listesi yuklenemedi. Bu alan zorunlu; referanslar gelene kadar kayit olusturulamaz.`
        : `${label} referansi yuklenemedi; bu alan simdilik secilemez.`}
    </p>
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
  createErrorMessage,
  referenceError,
  className
}: PersonelCreateFieldsProps) {
  return (
    <div className={className}>
      <div className="personel-form-columns">
        <div className="personel-form-column">
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
            label="Dogum Tarihi"
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
            label="Acil Durum Kisisi"
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
            label="Dogum Yeri"
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
            placeholderOption={{ value: "", label: "Seciniz" }}
            selectOptions={kanGrubuOptions}
          />
        </div>

        <div className="personel-form-column">
          <FormField
            label="Sicil No"
            name="create-sicil"
            value={form.sicilNo}
            onChange={(value) => setForm((prev) => ({ ...prev, sicilNo: value }))}
            required
          />
          <FormField
            label="Ise Giris Tarihi"
            name="create-ise-giris"
            type="date"
            value={form.iseGirisTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, iseGirisTarihi: value }))}
            required
          />
          {refs.bagliAmirOptions.length > 0 ? (
            <>
              <FormField
                as="select"
                label="Bagli Amir"
                name="create-bagli-amir"
                value={form.bagliAmirId}
                onChange={
                  onBagliAmirChange ??
                  ((value) => setForm((prev) => ({ ...prev, bagliAmirId: value })))
                }
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={toSelectOptions(refs.bagliAmirOptions)}
              />
              {bagliAmirInfoMessage ? (
                <p className="personel-form-note personel-form-note--info">{bagliAmirInfoMessage}</p>
              ) : null}
              {bagliAmirSubeWarning ? (
                <p className="personel-form-note personel-form-note--warning">{bagliAmirSubeWarning}</p>
              ) : null}
            </>
          ) : (
            refMissingNote("Bagli amir", false)
          )}
          {refs.departmanOptions.length > 0 ? (
            <>
              <FormField
                as="select"
                label="Bolum"
                name="create-departman"
                value={form.departmanId}
                onChange={
                  onDepartmanChange ??
                  ((value) => setForm((prev) => ({ ...prev, departmanId: value })))
                }
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={toSelectOptions(refs.departmanOptions)}
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
            <FormField
              as="select"
              label="Görev / Unvan"
              name="create-gorev"
              value={form.gorevId}
              onChange={(value) => setForm((prev) => ({ ...prev, gorevId: value }))}
              required
              placeholderOption={{ value: "", label: "Seciniz" }}
              selectOptions={toSelectOptions(refs.gorevOptions)}
            />
          ) : (
            refMissingNote("Görev / Unvan", true)
          )}
          {refs.personelTipiOptions.length > 0 ? (
            <FormField
              as="select"
              label="Personel Tipi"
              name="create-personel-tipi"
              value={form.personelTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, personelTipiId: value }))}
              required
              placeholderOption={{ value: "", label: "Seciniz" }}
              selectOptions={toSelectOptions(refs.personelTipiOptions)}
            />
          ) : (
            refMissingNote("Personel Tipi", true)
          )}
          {refs.ucretTipiOptions.length > 0 ? (
            <FormField
              as="select"
              label="Ücret Tipi"
              name="create-ucret-tipi"
              value={form.ucretTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, ucretTipiId: value }))}
              placeholderOption={{ value: "", label: "Seciniz" }}
              selectOptions={toSelectOptions(refs.ucretTipiOptions)}
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
          />
          {refs.primKuraliOptions.length > 0 ? (
            <FormField
              as="select"
              label="Prim Kuralı"
              name="create-prim-kurali"
              value={form.primKuraliId}
              onChange={(value) => setForm((prev) => ({ ...prev, primKuraliId: value }))}
              placeholderOption={{ value: "", label: "Seciniz" }}
              selectOptions={toSelectOptions(refs.primKuraliOptions)}
            />
          ) : (
            refMissingNote("Prim Kuralı", false)
          )}
        </div>
      </div>

      {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
      {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}
    </div>
  );
}
