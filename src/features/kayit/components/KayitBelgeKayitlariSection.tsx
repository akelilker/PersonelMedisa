import { FormEvent, useCallback, useEffect, useState } from "react";
import {
  cancelPersonelBelgeKaydi,
  createPersonelBelgeKaydi,
  fetchPersonelBelgeKayitlari
} from "../../../api/personel-belge-kayitlari.api";
import { getApiErrorMessage } from "../../../api/api-client";
import {
  createEmptyBelgeKaydiDraft,
  formatPersonelBelgeKayitTipiLabel,
  PERSONEL_BELGE_GECERLILIK_LABELS,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  PERSONEL_BELGE_KAYIT_TIPI_LABELS,
  type CreatePersonelBelgeKaydiPayload,
  type PersonelBelgeKaydi,
  type PersonelBelgeKayitTipi
} from "../../../types/personel-belge-kaydi";
import { formatIsoDateDetail } from "../../personeller/components/personel-dosya/personel-dosya-format-utils";

export const KAYIT_SUREC_BELGE_KAYITLARI_FORM_ID = "kayit-surec-belge-kayitlari-form";

function needsBitisTarihiWarning(tip: PersonelBelgeKayitTipi, bitisTarihi: string | null | undefined) {
  return (tip === "SERTIFIKA" || tip === "EHLIYET") && !bitisTarihi?.trim();
}

export function KayitBelgeKayitlariSection({
  personelId,
  personelLabel,
  isPersonelPasif,
  canWrite,
  isActive
}: {
  personelId: number;
  personelLabel: string;
  isPersonelPasif: boolean;
  canWrite: boolean;
  isActive: boolean;
}) {
  const [items, setItems] = useState<PersonelBelgeKaydi[]>([]);
  const [draft, setDraft] = useState<CreatePersonelBelgeKaydiPayload>(() => createEmptyBelgeKaydiDraft());
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [cancelingId, setCancelingId] = useState<number | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);
  const [validationNote, setValidationNote] = useState<string | null>(null);

  const loadItems = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const result = await fetchPersonelBelgeKayitlari(personelId, { state: "AKTIF", limit: 50 });
      setItems(result.items);
    } catch (err) {
      setItems([]);
      setErrorMessage(getApiErrorMessage(err, "Belge kayıtları yüklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }, [personelId]);

  useEffect(() => {
    if (!isActive || isPersonelPasif) {
      return;
    }
    void loadItems();
  }, [isActive, isPersonelPasif, loadItems]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canWrite || isPersonelPasif) {
      return;
    }

    const ad = draft.ad.trim();
    if (!ad) {
      setValidationNote("Ad alanı zorunludur.");
      return;
    }

    const bitisWarning = needsBitisTarihiWarning(draft.kayit_tipi, draft.bitis_tarihi);
    setValidationNote(bitisWarning ? "Sertifika ve ehliyet kayıtları için bitiş tarihi önerilir." : null);

    setIsSaving(true);
    setErrorMessage(null);
    setInfoMessage(null);
    try {
      await createPersonelBelgeKaydi(personelId, {
        kayit_tipi: draft.kayit_tipi,
        ad,
        veren_kurum: draft.veren_kurum?.trim() || null,
        belge_no: draft.belge_no?.trim() || null,
        baslangic_tarihi: draft.baslangic_tarihi?.trim() || null,
        bitis_tarihi: draft.bitis_tarihi?.trim() || null,
        ek_ref: draft.ek_ref?.trim() || null,
        aciklama: draft.aciklama?.trim() || null
      });
      setDraft(createEmptyBelgeKaydiDraft());
      setInfoMessage("Belge kaydı eklendi.");
      await loadItems();
    } catch (err) {
      setErrorMessage(getApiErrorMessage(err, "Belge kaydı eklenemedi."));
    } finally {
      setIsSaving(false);
    }
  }

  async function handleCancel(id: number) {
    if (!canWrite || isPersonelPasif) {
      return;
    }

    setCancelingId(id);
    setErrorMessage(null);
    setInfoMessage(null);
    try {
      await cancelPersonelBelgeKaydi(id);
      setInfoMessage("Belge kaydı iptal edildi.");
      await loadItems();
    } catch (err) {
      setErrorMessage(getApiErrorMessage(err, "Belge kaydı iptal edilemedi."));
    } finally {
      setCancelingId(null);
    }
  }

  if (isPersonelPasif) {
    return (
      <div className="surec-person-placeholder" data-testid="kayit-belge-kayitlari-pasif">
        <strong>Eğitim / Sertifika Kayıtları</strong>
        <p>Bu personel pasif; belge kaydı oluşturulamaz veya iptal edilemez.</p>
      </div>
    );
  }

  if (!canWrite) {
    return (
      <div className="surec-person-placeholder">
        <strong>Eğitim / Sertifika Kayıtları</strong>
        <p>Bu işlem için yetkin yok.</p>
      </div>
    );
  }

  return (
    <div className="belge-kayit-section" data-testid="kayit-belge-kayitlari-section">
      <p className="workspace-empty-hint">
        <strong>Eğitim / Sertifika / Ehliyet / Yetkinlik</strong> — {personelLabel}
      </p>

      {isLoading ? <p className="workspace-empty-hint">Belge kayıtları yükleniyor…</p> : null}
      {errorMessage ? <p className="workspace-error">{errorMessage}</p> : null}
      {infoMessage ? <p className="workspace-success workspace-success--inline">{infoMessage}</p> : null}

      {!isLoading ? (
        <div className="belge-kayit-list-wrap" data-testid="kayit-belge-kayitlari-list">
          {items.length === 0 ? (
            <p className="workspace-empty-hint">Henüz aktif belge kaydı yok.</p>
          ) : (
            <table className="belge-kayit-table">
              <thead>
                <tr>
                  <th>Tip</th>
                  <th>Ad</th>
                  <th>Bitiş</th>
                  <th>Geçerlilik</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id} data-testid={`kayit-belge-kayit-row-${item.id}`}>
                    <td>{formatPersonelBelgeKayitTipiLabel(item.kayit_tipi)}</td>
                    <td>{item.ad}</td>
                    <td>{formatIsoDateDetail(item.bitis_tarihi)}</td>
                    <td>{PERSONEL_BELGE_GECERLILIK_LABELS[item.gecerlilik_durumu]}</td>
                    <td>
                      <button
                        type="button"
                        className="universal-btn-aux belge-kayit-cancel-btn"
                        disabled={cancelingId === item.id}
                        onClick={() => void handleCancel(item.id)}
                      >
                        {cancelingId === item.id ? "İptal ediliyor..." : "İptal"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      ) : null}

      <form
        id={KAYIT_SUREC_BELGE_KAYITLARI_FORM_ID}
        className="workspace-form belge-kayit-form"
        onSubmit={handleSubmit}
      >
        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-tipi">
            Kayıt tipi
          </label>
          <select
            id="belge-kayit-tipi"
            name="belge-kayit-tipi"
            value={draft.kayit_tipi}
            onChange={(event) =>
              setDraft((prev) => ({
                ...prev,
                kayit_tipi: event.target.value as PersonelBelgeKayitTipi
              }))
            }
          >
            {PERSONEL_BELGE_KAYIT_TIPI_KEYS.map((tip) => (
              <option key={tip} value={tip}>
                {PERSONEL_BELGE_KAYIT_TIPI_LABELS[tip]}
              </option>
            ))}
          </select>
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-ad">
            Ad
          </label>
          <input
            id="belge-kayit-ad"
            name="belge-kayit-ad"
            value={draft.ad}
            onChange={(event) => setDraft((prev) => ({ ...prev, ad: event.target.value }))}
          />
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-veren-kurum">
            Veren kurum
          </label>
          <input
            id="belge-kayit-veren-kurum"
            name="belge-kayit-veren-kurum"
            value={draft.veren_kurum ?? ""}
            onChange={(event) => setDraft((prev) => ({ ...prev, veren_kurum: event.target.value }))}
          />
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-belge-no">
            Belge no
          </label>
          <input
            id="belge-kayit-belge-no"
            name="belge-kayit-belge-no"
            value={draft.belge_no ?? ""}
            onChange={(event) => setDraft((prev) => ({ ...prev, belge_no: event.target.value }))}
          />
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-baslangic">
            Başlangıç tarihi
          </label>
          <input
            id="belge-kayit-baslangic"
            name="belge-kayit-baslangic"
            type="date"
            value={draft.baslangic_tarihi ?? ""}
            onChange={(event) => setDraft((prev) => ({ ...prev, baslangic_tarihi: event.target.value }))}
          />
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-bitis">
            Bitiş / geçerlilik tarihi
          </label>
          <input
            id="belge-kayit-bitis"
            name="belge-kayit-bitis"
            type="date"
            value={draft.bitis_tarihi ?? ""}
            onChange={(event) => setDraft((prev) => ({ ...prev, bitis_tarihi: event.target.value }))}
          />
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-ek-ref">
            Ek referansı
          </label>
          <input
            id="belge-kayit-ek-ref"
            name="belge-kayit-ek-ref"
            value={draft.ek_ref ?? ""}
            onChange={(event) => setDraft((prev) => ({ ...prev, ek_ref: event.target.value }))}
          />
        </div>

        <div className="form-section">
          <label className="form-label" htmlFor="belge-kayit-aciklama">
            Açıklama
          </label>
          <textarea
            id="belge-kayit-aciklama"
            name="belge-kayit-aciklama"
            value={draft.aciklama ?? ""}
            onChange={(event) => setDraft((prev) => ({ ...prev, aciklama: event.target.value }))}
          />
        </div>

        {validationNote ? <p className="workspace-empty-hint">{validationNote}</p> : null}
      </form>

      <div className="universal-btn-group workspace-form-actions">
        <button
          type="submit"
          form={KAYIT_SUREC_BELGE_KAYITLARI_FORM_ID}
          className="universal-btn-save"
          disabled={isSaving || isLoading}
        >
          {isSaving ? "Kaydediliyor..." : "Kayıt Ekle"}
        </button>
      </div>
    </div>
  );
}
