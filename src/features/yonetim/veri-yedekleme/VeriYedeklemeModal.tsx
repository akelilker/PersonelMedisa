import { useCallback, useEffect, useState } from "react";
import { AppModal } from "../../../components/modal/AppModal";
import { SurecInlineBackButton } from "../../kayit/components/SurecInlineBackButton";
import { getApiErrorMessage } from "../../../api/api-client";
import {
  createVeriYedegi,
  downloadVeriYedegi,
  fetchVeriYedeklemeDurumu,
  type VeriYedegi
} from "../../../api/veri-yedekleme.api";
import { formatIsoDateTimeDetail } from "../../../lib/display/iso-date-format";

type VeriYedeklemeModalProps = {
  onClose: () => void;
  onBack: () => void;
};

function YedekAlIcon() {
  return (
    <svg className="veri-yedekleme-icon" viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
      <ellipse cx="20" cy="10" rx="13" ry="5" />
      <path d="M7 10v22c0 2.8 5.8 5 13 5" />
      <path d="M33 10v10" />
      <path d="M7 21c0 2.8 5.8 5 13 5s13-2.2 13-5" />
      <path d="M36 26v16" />
      <path d="m30 36 6 6 6-6" />
    </svg>
  );
}

function GeriYukleIcon() {
  return (
    <svg className="veri-yedekleme-icon" viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
      <path d="M8 4h16l8 8v12" />
      <path d="M24 4v8h8" />
      <path d="M8 4v34h12" />
      <ellipse cx="34" cy="30" rx="9" ry="3.5" />
      <path d="M25 30v10c0 1.9 4 3.5 9 3.5s9-1.6 9-3.5V30" />
      <path d="M25 35c0 1.9 4 3.5 9 3.5s9-1.6 9-3.5" />
    </svg>
  );
}

export function VeriYedeklemeModal({ onClose, onBack }: VeriYedeklemeModalProps) {
  const [sonYedek, setSonYedek] = useState<VeriYedegi | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const durum = await fetchVeriYedeklemeDurumu();
      setSonYedek(durum.son_yedek);
      setError(null);
    } catch (err) {
      setError(getApiErrorMessage(err, "Yedekleme İşlemi Tamamlanamadı."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleYedekAl() {
    setBusy(true);
    setMessage(null);
    setError(null);
    try {
      const yedek = await createVeriYedegi();
      setSonYedek(yedek);
      await downloadVeriYedegi(yedek.file);
      setMessage("Yedek Alındı ve Bilgisayarınıza İndirildi.");
    } catch (err) {
      setError(getApiErrorMessage(err, "Yedekleme İşlemi Tamamlanamadı."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AppModal
      title="VERİ YEDEKLEME"
      titleTestId="veri-yedekleme-title"
      onClose={onClose}
      className="veri-yedekleme-modal"
    >
      <SurecInlineBackButton label="Ayarlar" onClick={onBack} testId="veri-yedekleme-back" />
      <div className="veri-yedekleme-body" data-testid="veri-yedekleme-modal">
        <div className="veri-yedekleme-actions">
          <button
            type="button"
            className="veri-yedekleme-btn"
            data-testid="veri-yedekleme-yedek-al"
            onClick={() => void handleYedekAl()}
            disabled={busy || loading}
          >
            <YedekAlIcon />
            <span>{busy ? "Yedek Alınıyor..." : "Yedek Al"}</span>
          </button>
          <button
            type="button"
            className="veri-yedekleme-btn"
            data-testid="veri-yedekleme-geri-yukle"
            disabled
            title="Yedekten Geri Yükleme Bir Sonraki Sürümde Açılacak."
          >
            <GeriYukleIcon />
            <span>Yedekten Geri Yükle</span>
          </button>
        </div>
        <p className="veri-yedekleme-son" data-testid="veri-yedekleme-son">
          Sunucudaki Son Yedekleme Dosyası:{" "}
          <strong>{loading ? "..." : sonYedek ? formatIsoDateTimeDetail(sonYedek.created_at) : "Henüz Yedek Yok"}</strong>
        </p>
        <p className="veri-yedekleme-ipucu">Yedek Almak İçin &quot;Yedek Al&quot; Butonunu Kullanınız.</p>
        {message ? <p className="veri-yedekleme-ok" role="status">{message}</p> : null}
        {error ? <p className="veri-yedekleme-hata" role="alert">{error}</p> : null}
      </div>
    </AppModal>
  );
}
