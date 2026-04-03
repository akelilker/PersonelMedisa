import { type FormEvent } from "react";
import { useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import { formatAktifDurumLabel } from "../../../lib/display/enum-display";

function formatDetailValue(value: string | null | undefined) {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : "-";
}

function formatDetailNumber(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "-";
}

function formatReferenceValue(label?: string, id?: number) {
  if (label) {
    return label;
  }

  return typeof id === "number" ? `#${id}` : "-";
}

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
    { value: "AKTIF", label: formatAktifDurumLabel("AKTIF") },
    { value: "PASIF", label: formatAktifDurumLabel("PASIF") }
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
      <h2>Personel Detayı</h2>

      {isLoading ? <LoadingState label="Personel detayı yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadı" message="Belirtilen ID ile kayıt bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          {!isEditing ? (
            <>
              <div className="personel-detail-grid">
                <section className="personel-detail-section">
                  <h3>Ana Kart</h3>
                  <p>
                    <strong>Ad Soyad:</strong> {personel.ad} {personel.soyad}
                  </p>
                  <p>
                    <strong>T.C. Kimlik No:</strong> {personel.tc_kimlik_no}
                  </p>
                  <p>
                    <strong>Telefon:</strong> {formatDetailValue(personel.telefon)}
                  </p>
                  <p>
                    <strong>Doğum Tarihi:</strong> {formatDetailValue(personel.dogum_tarihi)}
                  </p>
                  <p>
                    <strong>Doğum Yeri:</strong> {formatDetailValue(personel.dogum_yeri)}
                  </p>
                  <p>
                    <strong>Kan Grubu:</strong> {formatDetailValue(personel.kan_grubu)}
                  </p>
                  <p>
                    <strong>Sicil No:</strong> {formatDetailValue(personel.sicil_no)}
                  </p>
                  <p>
                    <strong>İşe Giriş Tarihi:</strong> {formatDetailValue(personel.ise_giris_tarihi)}
                  </p>
                  <p>
                    <strong>Durum:</strong> {formatAktifDurumLabel(personel.aktif_durum)}
                  </p>
                </section>

                <section className="personel-detail-section">
                  <h3>Referanslar</h3>
                  <p>
                    <strong>Bölüm:</strong> {formatReferenceValue(personel.departman_adi, personel.departman_id)}
                  </p>
                  <p>
                    <strong>Görev:</strong> {formatReferenceValue(personel.gorev_adi, personel.gorev_id)}
                  </p>
                  <p>
                    <strong>Personel Tipi:</strong>{" "}
                    {formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)}
                  </p>
                  <p>
                    <strong>Bağlı Amir:</strong> {formatReferenceValue(personel.bagli_amir_adi, personel.bagli_amir_id)}
                  </p>
                  <p>
                    <strong>Acil Durum Kişisi:</strong> {formatDetailValue(personel.acil_durum_kisi)}
                  </p>
                  <p>
                    <strong>Acil Durum Telefonu:</strong> {formatDetailValue(personel.acil_durum_telefon)}
                  </p>
                </section>

                <section className="personel-detail-section">
                  <h3>Sistem Özeti</h3>
                  <p>
                    <strong>Hizmet Süresi:</strong> {formatDetailValue(personel.hizmet_suresi)}
                  </p>
                  <p>
                    <strong>Toplam İzin Hakkı:</strong> {formatDetailNumber(personel.toplam_izin_hakki)}
                  </p>
                  <p>
                    <strong>Kullanılan İzin:</strong> {formatDetailNumber(personel.kullanilan_izin)}
                  </p>
                  <p>
                    <strong>Kalan İzin:</strong> {formatDetailNumber(personel.kalan_izin)}
                  </p>
                  <p>
                    <strong>Pasiflik Etiketi:</strong> {formatDetailValue(personel.pasiflik_durumu_etiketi)}
                  </p>
                </section>
              </div>

              {canEditPersonel ? (
                <button type="button" className="universal-btn-aux" onClick={() => setIsEditing(true)}>
                  Düzenle
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
                  Vazgeç
                </button>
              </div>
            </form>
          )}
        </div>
      ) : null}
    </section>
  );
}
