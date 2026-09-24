import { type Dispatch, type FormEvent, type SetStateAction } from "react";
import { FormField } from "../../../../components/form/FormField";
import { CALISAN_KAPSAMI_SELECT_OPTIONS } from "../../../../lib/display/enum-display";
import type { PersonelReferenceBundle } from "../../../../data/app-data.types";
import type { IdOption } from "../../../../types/referans";
import type { EditPersonelFormState } from "../../personel-edit-utils";

function idOptionsToSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

export type PersonelInlineEditFormProps = {
  editForm: EditPersonelFormState;
  setEditForm: Dispatch<SetStateAction<EditPersonelFormState>>;
  personelRefs: PersonelReferenceBundle;
  editErrorMessage: string | null;
  isSubmitting: boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onDiscard: () => void;
  /** identity = Genel özlük; full kept for legacy call-sites but org fields are redirect notes only */
  variant?: "identity" | "full";
};

export function PersonelInlineEditForm({
  editForm,
  setEditForm,
  personelRefs,
  editErrorMessage,
  isSubmitting,
  onSubmit,
  onDiscard,
  variant = "identity"
}: PersonelInlineEditFormProps) {
  void variant;

  return (
    <form className="personel-edit-form" onSubmit={onSubmit} data-testid="personel-inline-edit-form">
      <div className="form-field-grid">
        <FormField
          as="select"
          label="Çalışan Kapsamı"
          name="edit-calisan-kapsami"
          value={editForm.calisanKapsami}
          onChange={(value) =>
            setEditForm((prev) => ({
              ...prev,
              calisanKapsami: value as EditPersonelFormState["calisanKapsami"]
            }))
          }
          selectOptions={CALISAN_KAPSAMI_SELECT_OPTIONS}
        />
        <FormField
          label="T.C. Kimlik No"
          name="edit-tc-kimlik-no"
          value={editForm.tcKimlikNo}
          onChange={(value) => setEditForm((prev) => ({ ...prev, tcKimlikNo: value }))}
          required={editForm.calisanKapsami === "IC_PERSONEL"}
        />
        <FormField
          label="Ad"
          name="edit-ad"
          value={editForm.ad}
          onChange={(value) => setEditForm((prev) => ({ ...prev, ad: value }))}
          required={editForm.calisanKapsami === "IC_PERSONEL"}
        />
        <FormField
          label="Doğum Tarihi"
          name="edit-dogum-tarihi"
          type="date"
          value={editForm.dogumTarihi}
          onChange={(value) => setEditForm((prev) => ({ ...prev, dogumTarihi: value }))}
          required={editForm.calisanKapsami === "IC_PERSONEL"}
        />
        <FormField
          label="Soyad"
          name="edit-soyad"
          value={editForm.soyad}
          onChange={(value) => setEditForm((prev) => ({ ...prev, soyad: value }))}
          required={editForm.calisanKapsami === "IC_PERSONEL"}
        />
        <FormField
          label="Telefon"
          name="edit-telefon"
          type="tel"
          value={editForm.telefon}
          onChange={(value) => setEditForm((prev) => ({ ...prev, telefon: value }))}
          required={editForm.calisanKapsami === "IC_PERSONEL"}
        />
        <FormField
          label="Sicil No"
          name="edit-sicil-no"
          value={editForm.sicilNo ?? ""}
          onChange={(value) => setEditForm((prev) => ({ ...prev, sicilNo: value }))}
          required
        />
        <FormField
          label="İşe Giriş Tarihi"
          name="edit-ise-giris-tarihi"
          type="date"
          value={editForm.iseGirisTarihi ?? ""}
          onChange={(value) => setEditForm((prev) => ({ ...prev, iseGirisTarihi: value }))}
          required
        />
        <p
          className="personel-form-note personel-form-note--info"
          data-testid="personel-edit-org-yonlendirme"
        >
          Departman, bölüm, birim, görev/unvan, pozisyon, bağlı amir, Statü, çalışma lokasyonu ve SGK
          değişiklikleri Süreç → Görev / Organizasyon sekmesinden yapılır.
        </p>
        <p
          className="personel-form-note personel-form-note--info"
          data-testid="personel-edit-ucret-yonlendirme"
        >
          Ücret tipi ve maaş Süreç → Mali İşlemler üzerinden yönetilir; Genel düzenleme ücret yazmaz.
        </p>
        {personelRefs.primKuraliOptions.length > 0 ? (
          <FormField
            as="select"
            label="Prim kuralı"
            name="edit-prim-kurali-id"
            value={editForm.primKuraliId}
            onChange={(value) => setEditForm((prev) => ({ ...prev, primKuraliId: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={idOptionsToSelectOptions(personelRefs.primKuraliOptions)}
          />
        ) : (
          <p className="personel-create-error">Prim kuralı listesi yüklenemedi.</p>
        )}
      </div>

      {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

      <div className="universal-btn-group">
        <button type="submit" className="universal-btn-save" disabled={isSubmitting}>
          {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
        </button>
        <button type="button" className="universal-btn-cancel" onClick={onDiscard} disabled={isSubmitting}>
          Vazgeç
        </button>
      </div>
    </form>
  );
}
