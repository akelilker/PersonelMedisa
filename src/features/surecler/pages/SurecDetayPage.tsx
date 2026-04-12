import { Link, useParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useSurecDetail } from "../../../hooks/useSurecler";
import {
  formatSurecStateLabel,
  formatSurecTuruLabel,
  formatUcretliMiLabel
} from "../../../lib/display/enum-display";

export function SurecDetayPage() {
  const { surecId } = useParams();
  const parsedSurecId = Number.parseInt(surecId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedSurecId) && parsedSurecId > 0;

  const { surec, isLoading, errorMessage, refetch } = useSurecDetail(parsedSurecId, hasValidId);

  return (
    <section className="surec-page surec-detay-page">
      <h2>Süreç Detayı</h2>

      {isLoading ? <LoadingState label="Süreç detayı yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !surec ? (
        <EmptyState title="Süreç bulunamadı" message="Belirtilen ID ile kayıt bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && surec ? (
        <div className="surec-detail-card">
          <p>
            <strong>Süreç ID:</strong> {surec.id}
          </p>
          <p>
            <strong>Personel ID:</strong> {surec.personel_id}
          </p>
          <p>
            <strong>Süreç Türü:</strong> {formatSurecTuruLabel(surec.surec_turu)}
          </p>
          <p>
            <strong>Alt Tur:</strong> {surec.alt_tur ?? "-"}
          </p>
          <p>
            <strong>Başlangıç:</strong> {surec.baslangic_tarihi ?? "-"}
          </p>
          <p>
            <strong>Bitiş:</strong> {surec.bitis_tarihi ?? "-"}
          </p>
          <p>
            <strong>Ücretli Mi:</strong> {formatUcretliMiLabel(surec.ucretli_mi)}
          </p>
          <p>
            <strong>Durum:</strong> {formatSurecStateLabel(surec.state)}
          </p>
          <p>
            <strong>Açıklama:</strong> {surec.aciklama ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/bildirimler">Gunluk kayit merkezine git</Link>
      </div>
    </section>
  );
}
