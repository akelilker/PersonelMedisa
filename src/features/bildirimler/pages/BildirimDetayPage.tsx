import { Link, useParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useBildirimDetail } from "../../../hooks/useBildirimler";
import { formatBildirimStateLabel } from "../../../lib/display/enum-display";
import {
  formatGunlukKayitDayanak,
  formatGunlukKayitGunTipi,
  formatGunlukKayitHareketDurumu,
  formatGunlukKayitHesapEtkisi,
  resolveGunlukKayitPreset
} from "../gunluk-kayit-presets";

export function BildirimDetayPage() {
  const { bildirimId } = useParams();
  const parsedBildirimId = Number.parseInt(bildirimId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedBildirimId) && parsedBildirimId > 0;

  const { bildirim, isLoading, errorMessage, refetch } = useBildirimDetail(parsedBildirimId, hasValidId);
  const preset = resolveGunlukKayitPreset(bildirim?.bildirim_turu);

  return (
    <section className="bildirimler-page bildirim-detay-page">
      <h2>Günlük Kayıt Detayı</h2>

      {isLoading ? <LoadingState label="Günlük kayıt detayı yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !bildirim ? (
        <EmptyState title="Günlük kayıt bulunamadı" message="Belirtilen ID ile kayıt bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && bildirim ? (
        <div className="bildirim-detail-card">
          <p>
            <strong>Kayıt ID:</strong> {bildirim.id}
          </p>
          <p>
            <strong>Tarih:</strong> {bildirim.tarih ?? "-"}
          </p>
          <p>
            <strong>Departman ID:</strong> {bildirim.departman_id ?? "-"}
          </p>
          <p>
            <strong>Personel ID:</strong> {bildirim.personel_id ?? "-"}
          </p>
          <p>
            <strong>Kayıt Senaryosu:</strong> {preset.label}
          </p>
          <p>
            <strong>Kayıt Durumu:</strong> {formatBildirimStateLabel(bildirim.state)}
          </p>
          <p>
            <strong>Gün Tipi:</strong> {formatGunlukKayitGunTipi(preset.gunTipi)}
          </p>
          <p>
            <strong>Hareket Durumu:</strong> {formatGunlukKayitHareketDurumu(preset.hareketDurumu)}
          </p>
          <p>
            <strong>Dayanak:</strong> {formatGunlukKayitDayanak(preset.dayanak)}
          </p>
          <p>
            <strong>Hesap Etkisi:</strong> {formatGunlukKayitHesapEtkisi(preset.hesapEtkisi)}
          </p>
          <p>
            <strong>Açıklama:</strong> {bildirim.aciklama ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/surecler">Süreç takibe git</Link>
        <Link to="/puantaj">Puantaj ekranına git</Link>
      </div>
    </section>
  );
}
