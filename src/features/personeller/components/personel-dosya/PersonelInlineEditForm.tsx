import { type Dispatch, type FormEvent, type SetStateAction } from "react";
import { FormField } from "../../../../components/form/FormField";
import type { PersonelReferenceBundle } from "../../../../data/app-data.types";
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
  onSubmit,
  onDiscard
}: PersonelInlineEditFormProps) {
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
          selectOptions={[
            { value: "IC_PERSONEL", label: "İç Personel" },
            { value: "DIS_KAYNAK", label: "Dış Kaynak / SGK Başka İşverende" }
          ]}
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
        {personelRefs.bolumOptions.length > 0 ? (
          <FormField
            as="select"
            label="Bölüm"
            name="edit-bolum"
            value={editForm.bolumId}
            onChange={(value) => {
              const nextBirimId =
                value &&
                editForm.birimId &&
                personelRefs.birimOptions.some(
                  (opt) => String(opt.id) === editForm.birimId && String(opt.parentId ?? "") === value
                )
                  ? editForm.birimId
                  : "";
              setEditForm((prev) => ({ ...prev, bolumId: value, birimId: nextBirimId }));
            }}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={idOptionsToSelectOptions(
              personelRefs.bolumOptions.filter(
                (opt) =>
                  !editForm.departmanId || String(opt.parentId ?? "") === editForm.departmanId
              )
            )}
            disabled={!editForm.departmanId}
          />
        ) : null}
        {personelRefs.birimOptions.length > 0 ? (
          <FormField
            as="select"
            label="Birim"
            name="edit-birim"
            value={editForm.birimId}
            onChange={(value) => setEditForm((prev) => ({ ...prev, birimId: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={idOptionsToSelectOptions(
              personelRefs.birimOptions.filter(
                (opt) => !editForm.bolumId || String(opt.parentId ?? "") === editForm.bolumId
              )
            )}
            disabled={!editForm.bolumId}
          />
        ) : null}
        {personelRefs.gorevOptions.length > 0 ? (
          <FormField
            as="select"
            label="Unvan"
            name="edit-gorev"
            value={editForm.gorevId}
            onChange={(value) => setEditForm((prev) => ({ ...prev, gorevId: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={idOptionsToSelectOptions(personelRefs.gorevOptions)}
          />
        ) : (
          <p className="personel-create-error">Unvan listesi yüklenemedi.</p>
        )}
        {personelRefs.pozisyonOptions.length > 0 ? (
          <FormField
            as="select"
            label="Pozisyon"
            name="edit-pozisyon"
            value={editForm.pozisyonId}
            onChange={(value) => setEditForm((prev) => ({ ...prev, pozisyonId: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            selectOptions={idOptionsToSelectOptions(personelRefs.pozisyonOptions)}
          />
        ) : null}
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
