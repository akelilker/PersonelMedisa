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
      <h2>Gunluk Kayit Detayi</h2>

      {isLoading ? <LoadingState label="Gunluk kayit detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !bildirim ? (
        <EmptyState title="Gunluk kayit bulunamadi" message="Belirtilen ID ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && bildirim ? (
        <div className="bildirim-detail-card">
          <p>
            <strong>Kayit ID:</strong> {bildirim.id}
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
            <strong>Kayit Senaryosu:</strong> {preset.label}
          </p>
          <p>
            <strong>Kayit Durumu:</strong> {formatBildirimStateLabel(bildirim.state)}
          </p>
          <p>
            <strong>Gun Tipi:</strong> {formatGunlukKayitGunTipi(preset.gunTipi)}
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
            <strong>Aciklama:</strong> {bildirim.aciklama ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/surecler">Surec takibe git</Link>
        <Link to="/puantaj">Puantaj ekranina git</Link>
      </div>
    </section>
  );
}
