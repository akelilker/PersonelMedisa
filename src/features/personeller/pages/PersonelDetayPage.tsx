import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import { fetchPersonelDetail, updatePersonel } from "../../../api/personeller.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { Personel } from "../../../types/personel";

type EditFormState = {
  ad: string;
  soyad: string;
  telefon: string;
  aktifDurum: "AKTIF" | "PASIF";
};

export function PersonelDetayPage() {
  const { personelId } = useParams();
  const parsedPersonelId = Number.parseInt(personelId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedPersonelId) && parsedPersonelId > 0;
  const { hasPermission } = useRoleAccess();
  const canEditPersonel = hasPermission("personeller.update");

  const [personel, setPersonel] = useState<Personel | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditFormState>({
    ad: "",
    soyad: "",
    telefon: "",
    aktifDurum: "AKTIF"
  });

  const loadPersonel = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir personel id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchPersonelDetail(parsedPersonelId);
      setPersonel(data);
      setEditForm({
        ad: data.ad,
        soyad: data.soyad,
        telefon: data.telefon ?? "",
        aktifDurum: data.aktif_durum
      });
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Personel detayi alinamadi.");
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedPersonelId]);

  useEffect(() => {
    void loadPersonel();
  }, [loadPersonel]);

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!personel || isSubmitting) {
      return;
    }
    if (!canEditPersonel) {
      setEditErrorMessage("Bu kaydi guncellemek icin yetkin bulunmuyor.");
      return;
    }

    setEditErrorMessage(null);
    setIsSubmitting(true);

    try {
      const updated = await updatePersonel(personel.id, {
        ad: editForm.ad.trim(),
        soyad: editForm.soyad.trim(),
        telefon: editForm.telefon.trim(),
        aktif_durum: editForm.aktifDurum
      });

      setPersonel(updated);
      setEditForm({
        ad: updated.ad,
        soyad: updated.soyad,
        telefon: updated.telefon ?? "",
        aktifDurum: updated.aktif_durum
      });
      setIsEditing(false);
    } catch (error) {
      setEditErrorMessage(error instanceof Error ? error.message : "Kayit guncellenemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <section className="personel-detay-page">
      <h2>Personel Detay</h2>

      {isLoading ? <LoadingState label="Personel detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadPersonel()} />
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
                <button type="button" className="state-action-btn" onClick={() => setIsEditing(true)}>
                  Duzenle
                </button>
              ) : null}
            </>
          ) : (
            <form className="personel-edit-form" onSubmit={handleEditSubmit}>
              <label className="module-filter-field">
                <span>Ad</span>
                <input
                  type="text"
                  value={editForm.ad}
                  onChange={(event) => setEditForm((prev) => ({ ...prev, ad: event.target.value }))}
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Soyad</span>
                <input
                  type="text"
                  value={editForm.soyad}
                  onChange={(event) =>
                    setEditForm((prev) => ({ ...prev, soyad: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Telefon</span>
                <input
                  type="tel"
                  value={editForm.telefon}
                  onChange={(event) =>
                    setEditForm((prev) => ({ ...prev, telefon: event.target.value }))
                  }
                />
              </label>

              <label className="module-filter-field">
                <span>Durum</span>
                <select
                  value={editForm.aktifDurum}
                  onChange={(event) =>
                    setEditForm((prev) => ({
                      ...prev,
                      aktifDurum: event.target.value as "AKTIF" | "PASIF"
                    }))
                  }
                >
                  <option value="AKTIF">AKTIF</option>
                  <option value="PASIF">PASIF</option>
                </select>
              </label>

              {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

              <div className="module-filter-actions">
                <button type="submit" className="state-action-btn" disabled={isSubmitting}>
                  {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
                </button>
                <button
                  type="button"
                  className="state-action-btn"
                  onClick={() => {
                    setIsEditing(false);
                    setEditErrorMessage(null);
                    setEditForm({
                      ad: personel.ad,
                      soyad: personel.soyad,
                      telefon: personel.telefon ?? "",
                      aktifDurum: personel.aktif_durum
                    });
                  }}
                  disabled={isSubmitting}
                >
                  Vazgec
                </button>
              </div>
            </form>
          )}
        </div>
      ) : null}

      <Link to="/personeller">Personel listesine don</Link>
    </section>
  );
}
