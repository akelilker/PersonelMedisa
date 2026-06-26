import { type Dispatch, type FormEvent, type SetStateAction } from "react";
import { AppModal } from "../../../../components/modal/AppModal";
import type { PersonelZimmetFormState } from "../../../../hooks/usePersoneller";
import { PersonelZimmetCreateForm } from "../PersonelZimmetCreateForm";

const PERSONEL_ZIMMET_FORM_ID = "personel-zimmet-form";

export type PersonelZimmetCreateModalProps = {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  zimmetForm: PersonelZimmetFormState;
  setZimmetForm: Dispatch<SetStateAction<PersonelZimmetFormState>>;
  isSubmitting: boolean;
  zimmetCreateErrorMessage: string | null;
};

export function PersonelZimmetCreateModal({
  isOpen,
  onClose,
  onSubmit,
  zimmetForm,
  setZimmetForm,
  isSubmitting,
  zimmetCreateErrorMessage
}: PersonelZimmetCreateModalProps) {
  if (!isOpen) {
    return null;
  }

  return (
    <AppModal
      title="Yeni Zimmet Ekle"
      onClose={onClose}
      footer={
        <div className="universal-btn-group modal-footer-actions">
          <button
            type="submit"
            form={PERSONEL_ZIMMET_FORM_ID}
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
      <PersonelZimmetCreateForm
        formId={PERSONEL_ZIMMET_FORM_ID}
        zimmetForm={zimmetForm}
        setZimmetForm={setZimmetForm}
        onSubmit={onSubmit}
        zimmetCreateErrorMessage={zimmetCreateErrorMessage}
      />
    </AppModal>
  );
}
