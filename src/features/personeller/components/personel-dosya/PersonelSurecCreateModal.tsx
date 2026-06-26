import { type Dispatch, type FormEvent, type SetStateAction } from "react";
import { FormField } from "../../../../components/form/FormField";
import { AppModal } from "../../../../components/modal/AppModal";
import type { KeyOption } from "../../../../types/referans";
import { keyOptionsToSelectOptions } from "./personel-modal-utils";

const PERSONEL_SUREC_FORM_ID = "personel-surec-form";

export type PersonelSurecFormState = {
  surecTuru: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  aciklama: string;
};

export type PersonelSurecCreateModalProps = {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  surecForm: PersonelSurecFormState;
  setSurecForm: Dispatch<SetStateAction<PersonelSurecFormState>>;
  surecTuruOptions: KeyOption[];
  isSubmitting: boolean;
  surecCreateErrorMessage: string | null;
  surecReferenceErrorMessage: string | null;
};

export function PersonelSurecCreateModal({
  isOpen,
  onClose,
  onSubmit,
  surecForm,
  setSurecForm,
  surecTuruOptions,
  isSubmitting,
  surecCreateErrorMessage,
  surecReferenceErrorMessage
}: PersonelSurecCreateModalProps) {
  if (!isOpen) {
    return null;
  }

  return (
    <AppModal
      title="Süreç Ekle"
      onClose={onClose}
      footer={
        <div className="universal-btn-group modal-footer-actions">
          <button
            type="submit"
            form={PERSONEL_SUREC_FORM_ID}
            className="universal-btn-save"
            disabled={isSubmitting}
          >
            {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
          </button>
          <button type="button" className="universal-btn-cancel" onClick={onClose} disabled={isSubmitting}>
            Vazgeç
          </button>
        </div>
      }
    >
      <form id={PERSONEL_SUREC_FORM_ID} className="personel-surec-form-grid" onSubmit={onSubmit}>
        {surecTuruOptions.length > 0 ? (
          <FormField
            as="select"
            label="Süreç Türü"
            name="personel-surec-turu"
            value={surecForm.surecTuru}
            onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
            required
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
          />
        ) : (
          <FormField
            label="Süreç Türü"
            name="personel-surec-turu-text"
            value={surecForm.surecTuru}
            onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
            required
            placeholder="IZIN, RAPOR, ISTEN_AYRILMA"
          />
        )}
        <FormField
          label="Başlangıç Tarihi"
          name="personel-surec-baslangic"
          type="date"
          value={surecForm.baslangicTarihi}
          onChange={(value) => setSurecForm((prev) => ({ ...prev, baslangicTarihi: value }))}
          required
        />
        <FormField
          label="Bitiş Tarihi"
          name="personel-surec-bitis"
          type="date"
          value={surecForm.bitisTarihi}
          onChange={(value) => setSurecForm((prev) => ({ ...prev, bitisTarihi: value }))}
        />
        <FormField
          as="textarea"
          label="Açıklama"
          name="personel-surec-aciklama"
          value={surecForm.aciklama}
          onChange={(value) => setSurecForm((prev) => ({ ...prev, aciklama: value }))}
          rows={4}
        />
        {surecCreateErrorMessage ? <p className="personel-create-error">{surecCreateErrorMessage}</p> : null}
        {surecReferenceErrorMessage ? <p className="personel-create-error">{surecReferenceErrorMessage}</p> : null}
      </form>
    </AppModal>
  );
}
