import { useState, type FormEvent } from "react";
import { FormField } from "../../../../components/form/FormField";
import { AppModal } from "../../../../components/modal/AppModal";
import type { CreatePersonelUcretPayload, UcretTuru } from "../../../../types/ucret";

const PERSONEL_UCRET_FORM_ID = "personel-ucret-form";

const UCRET_TURU_OPTIONS = [
  { value: "NET", label: "Net" },
  { value: "BRUT", label: "Brüt" }
];

type UcretFormState = {
  ucretTutari: string;
  ucretTuru: UcretTuru;
  paraBirimi: string;
  gecerlilikBaslangic: string;
  gecerlilikBitis: string;
  aciklama: string;
};

const INITIAL_UCRET_FORM: UcretFormState = {
  ucretTutari: "",
  ucretTuru: "NET",
  paraBirimi: "TRY",
  gecerlilikBaslangic: "",
  gecerlilikBitis: "",
  aciklama: ""
};

export type PersonelUcretCreateModalProps = {
  isOpen: boolean;
  onClose: () => void;
  onCreate: (payload: CreatePersonelUcretPayload) => Promise<boolean>;
  isSubmitting: boolean;
  submitErrorMessage: string | null;
};

export function PersonelUcretCreateModal({
  isOpen,
  onClose,
  onCreate,
  isSubmitting,
  submitErrorMessage
}: PersonelUcretCreateModalProps) {
  const [form, setForm] = useState<UcretFormState>(INITIAL_UCRET_FORM);
  const [validationMessage, setValidationMessage] = useState<string | null>(null);

  if (!isOpen) {
    return null;
  }

  function handleClose() {
    setForm(INITIAL_UCRET_FORM);
    setValidationMessage(null);
    onClose();
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    const tutar = Number.parseFloat(form.ucretTutari.replace(",", "."));
    if (!Number.isFinite(tutar) || tutar <= 0) {
      setValidationMessage("Ücret tutarı sıfırdan büyük olmalıdır.");
      return;
    }

    if (!form.gecerlilikBaslangic) {
      setValidationMessage("Geçerlilik başlangıç tarihi zorunludur.");
      return;
    }

    if (form.gecerlilikBitis && form.gecerlilikBitis < form.gecerlilikBaslangic) {
      setValidationMessage("Bitiş tarihi başlangıç tarihinden önce olamaz.");
      return;
    }

    setValidationMessage(null);

    const payload: CreatePersonelUcretPayload = {
      ucret_tutari: tutar,
      ucret_turu: form.ucretTuru,
      para_birimi: form.paraBirimi.trim().toUpperCase() || "TRY",
      gecerlilik_baslangic: form.gecerlilikBaslangic,
      gecerlilik_bitis: form.gecerlilikBitis || null
    };
    const aciklama = form.aciklama.trim();
    if (aciklama) {
      payload.aciklama = aciklama;
    }

    const success = await onCreate(payload);
    if (success) {
      setForm(INITIAL_UCRET_FORM);
      onClose();
    }
  }

  return (
    <AppModal
      title="Yeni Ücret Dönemi Başlat"
      onClose={handleClose}
      footer={
        <div className="universal-btn-group modal-footer-actions">
          <button
            type="submit"
            form={PERSONEL_UCRET_FORM_ID}
            className="universal-btn-save"
            disabled={isSubmitting}
            data-testid="personel-ucret-form-kaydet"
          >
            {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
          </button>
          <button
            type="button"
            className="universal-btn-cancel"
            onClick={handleClose}
            disabled={isSubmitting}
          >
            Vazgeç
          </button>
        </div>
      }
    >
      <form id={PERSONEL_UCRET_FORM_ID} className="workspace-form" onSubmit={handleSubmit}>
        <div className="form-field-grid">
          <FormField
            label="Ücret Tutarı"
            name="ucret-tutar"
            type="number"
            min={0}
            step="0.01"
            value={form.ucretTutari}
            onChange={(value) => setForm((prev) => ({ ...prev, ucretTutari: value }))}
            placeholder="Örn. 35000"
            required
          />
          <FormField
            as="select"
            label="Ücret Türü"
            name="ucret-turu"
            value={form.ucretTuru}
            onChange={(value) =>
              setForm((prev) => ({ ...prev, ucretTuru: value === "BRUT" ? "BRUT" : "NET" }))
            }
            selectOptions={UCRET_TURU_OPTIONS}
            required
          />
          <FormField
            label="Para Birimi"
            name="ucret-para-birimi"
            value={form.paraBirimi}
            onChange={(value) => setForm((prev) => ({ ...prev, paraBirimi: value }))}
            placeholder="Örn. TRY"
          />
          <FormField
            label="Geçerlilik Başlangıç"
            name="ucret-baslangic"
            type="date"
            value={form.gecerlilikBaslangic}
            onChange={(value) => setForm((prev) => ({ ...prev, gecerlilikBaslangic: value }))}
            required
          />
          <FormField
            label="Geçerlilik Bitiş"
            name="ucret-bitis"
            type="date"
            value={form.gecerlilikBitis}
            onChange={(value) => setForm((prev) => ({ ...prev, gecerlilikBitis: value }))}
          />
          <FormField
            as="textarea"
            label="Açıklama"
            name="ucret-aciklama"
            value={form.aciklama}
            onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
            placeholder="Opsiyonel açıklama"
          />
        </div>

        {validationMessage ? (
          <p className="personel-create-error" role="alert">
            {validationMessage}
          </p>
        ) : null}
        {submitErrorMessage ? (
          <p className="personel-create-error" role="alert" data-testid="personel-ucret-form-hata">
            {submitErrorMessage}
          </p>
        ) : null}
      </form>
    </AppModal>
  );
}
