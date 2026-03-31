import { type FormEvent } from "react";
import { useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";

export function PersonelDetayPage() {
  const { personelId } = useParams();
  const parsedPersonelId = Number.parseInt(personelId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedPersonelId) && parsedPersonelId > 0;
  const { hasPermission } = useRoleAccess();
  const canEditPersonel = hasPermission("personeller.update");

  const {
    personel,
    isLoading,
    errorMessage,
    refetch,
    isEditing,
    setIsEditing,
    isSubmitting,
    editErrorMessage,
    editForm,
    setEditForm,
    discardEdit,
    updatePersonelHandler
  } = usePersonelDetail(parsedPersonelId, hasValidId);

  const aktifDurumOptions = [
    { value: "AKTIF", label: "AKTIF" },
    { value: "PASIF", label: "PASIF" }
  ];

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  function cancelEdit() {
    if (!personel) {
      return;
    }
    setIsEditing(false);
    setEditForm({
      ad: personel.ad,
      soyad: personel.soyad,
      telefon: personel.telefon ?? "",
      aktifDurum: personel.aktif_durum
    });
  }

  return (
    <section className="personel-detay-page">
      <h2>Personel Detay</h2>

      {isLoading ? <LoadingState label="Personel detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadi" message="Belirtilen id ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          {!isEditing ? (
            <>
              <p>
                <strong>Ad Soyad:</strong> {personel.ad} {personel.soyad}
              </p>
              <p>
                <strong>T.C. Kimlik No:</strong> {personel.tc_kimlik_no}
              </p>
              <p>
                <strong>Telefon:</strong> {personel.telefon ?? "-"}
              </p>
              <p>
                <strong>Durum:</strong> {personel.aktif_durum}
              </p>

              {canEditPersonel ? (
                <button type="button" className="universal-btn-aux" onClick={() => setIsEditing(true)}>
                  Duzenle
                </button>
              ) : null}
            </>
          ) : (
            <form className="personel-edit-form" onSubmit={handleEditSubmit}>
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
              <FormField
                as="select"
                label="Durum"
                name="edit-aktif"
                value={editForm.aktifDurum}
                onChange={(value) =>
                  setEditForm((prev) => ({ ...prev, aktifDurum: value as "AKTIF" | "PASIF" }))
                }
                selectOptions={aktifDurumOptions}
              />

              {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

              <div className="form-actions-row">
                <button type="submit" className="universal-btn-aux" disabled={isSubmitting}>
                  {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
                </button>
                <button type="button" className="universal-btn-aux" onClick={discardEdit} disabled={isSubmitting}>
                  Vazgec
                </button>
              </div>
            </form>
          )}
        </div>
      ) : null}
    </section>
  );
}
