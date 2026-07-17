import { type Dispatch, type FormEvent, type SetStateAction } from "react";
import { FormField } from "../../../../components/form/FormField";
import type { PersonelReferenceBundle } from "../../../../data/app-data.types";
import { mapUcretTipiSelectOptions } from "../../../../lib/display/ucret-tipi-display";
import type { IdOption } from "../../../../types/referans";
import type { BagliAmirFormGuidance, EditPersonelFormState } from "../../personel-edit-utils";

function idOptionsToSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

export type PersonelInlineEditFormProps = {
  editForm: EditPersonelFormState;
  setEditForm: Dispatch<SetStateAction<EditPersonelFormState>>;
  handleEditDepartmanChange: (departmanId: string) => void;
  handleEditBagliAmirChange: (bagliAmirId: string) => void;
  editBagliAmirGuidance: BagliAmirFormGuidance;
  personelRefs: PersonelReferenceBundle;
  hasLifecycleDiff: boolean;
  editErrorMessage: string | null;
  isSubmitting: boolean;
  canManageUcret: boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onDiscard: () => void;
};

export function PersonelInlineEditForm({
  editForm,
  setEditForm,
  handleEditDepartmanChange,
  handleEditBagliAmirChange,
  editBagliAmirGuidance,
  personelRefs,
  hasLifecycleDiff,
  editErrorMessage,
  isSubmitting,
  canManageUcret,
  onSubmit,
  onDiscard
}: PersonelInlineEditFormProps) {
  return (
    <form className="personel-edit-form" onSubmit={onSubmit}>
      <div className="form-field-grid">
        <FormField
          label="Ad"
          name="edit-ad"
          value={editForm.ad}
          onChange={(value) => setEditForm((prev) => ({ ...prev, ad: value }))}
          required
        />
        <FormField
          label="Soyad"
          name="edit-soyad"
          value={editForm.soyad}
          onChange={(value) => setEditForm((prev) => ({ ...prev, soyad: value }))}
          required
        />
        <FormField
          label="Telefon"
          name="edit-telefon"
          type="tel"
          value={editForm.telefon}
          onChange={(value) => setEditForm((prev) => ({ ...prev, telefon: value }))}
        />
        {personelRefs.bagliAmirOptions.length > 0 ? (
          <>
            <FormField
              as="select"
              label="Bağlı amir"
              name="edit-bagli-amir"
              value={editForm.bagliAmirId}
              onChange={handleEditBagliAmirChange}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={idOptionsToSelectOptions(personelRefs.bagliAmirOptions)}
            />
            {editBagliAmirGuidance.infoMessage ? (
              <p className="personel-form-note personel-form-note--info">
                {editBagliAmirGuidance.infoMessage}
              </p>
            ) : null}
            {editBagliAmirGuidance.subeWarning ? (
              <p className="personel-form-note personel-form-note--warning">
                {editBagliAmirGuidance.subeWarning}
              </p>
            ) : null}
          </>
        ) : (
          <p className="personel-create-error">Bağlı amir listesi yüklenemedi.</p>
        )}
        {personelRefs.departmanOptions.length > 0 ? (
          <>
            <FormField
              as="select"
              label="Departman"
              name="edit-departman"
              value={editForm.departmanId}
              onChange={handleEditDepartmanChange}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={idOptionsToSelectOptions(personelRefs.departmanOptions)}
            />
            {editBagliAmirGuidance.departmanWarning ? (
              <p className="personel-form-note personel-form-note--warning">
                {editBagliAmirGuidance.departmanWarning}
              </p>
            ) : null}
          </>
        ) : (
          <p className="personel-create-error">Departman listesi yüklenemedi.</p>
        )}
        {personelRefs.gorevOptions.length > 0 ? (
          <FormField
            as="select"
            label="Görev / Unvan"
            name="edit-gorev"
            value={editForm.gorevId}
            onChange={(value) => setEditForm((prev) => ({ ...prev, gorevId: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={idOptionsToSelectOptions(personelRefs.gorevOptions)}
          />
        ) : (
          <p className="personel-create-error">Görev / Unvan listesi yüklenemedi.</p>
        )}
        {personelRefs.ucretTipiOptions.length > 0 ? (
          <FormField
            as="select"
            label="Ücret tipi"
            name="edit-ucret-tipi-id"
            value={editForm.ucretTipiId}
            onChange={(value) => setEditForm((prev) => ({ ...prev, ucretTipiId: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={mapUcretTipiSelectOptions(personelRefs.ucretTipiOptions)}
          />
        ) : (
          <p className="personel-create-error">Ücret tipi listesi yüklenemedi.</p>
        )}
        {canManageUcret ? (
          <p
            className="personel-form-note personel-form-note--info"
            data-testid="personel-edit-ucret-yonlendirme"
          >
            Maaş bilgisi artık Genel sekmesindeki Ücret Geçmişi bölümünden yönetilir; yeni tutar
            için oradan yeni ücret dönemi başlatın.
          </p>
        ) : null}
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
        {hasLifecycleDiff ? (
          <FormField
            label="Geçerlilik Tarihi"
            name="edit-effective-date"
            type="date"
            value={editForm.effectiveDate}
            onChange={(value) => setEditForm((prev) => ({ ...prev, effectiveDate: value }))}
            required
          />
        ) : null}
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
