import { Link, useParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useBildirimDetail } from "../../../hooks/useBildirimler";

export function BildirimDetayPage() {
  const { bildirimId } = useParams();
  const parsedBildirimId = Number.parseInt(bildirimId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedBildirimId) && parsedBildirimId > 0;

  const { bildirim, isLoading, errorMessage, refetch } = useBildirimDetail(parsedBildirimId, hasValidId);

  return (
    <section className="bildirimler-page bildirim-detay-page">
      <h2>Bildirim Detay</h2>

      {isLoading ? <LoadingState label="Bildirim detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !bildirim ? (
        <EmptyState title="Bildirim bulunamadi" message="Belirtilen id ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && bildirim ? (
        <div className="bildirim-detail-card">
          <p>
            <strong>Bildirim ID:</strong> {bildirim.id}
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
            <strong>Bildirim Turu:</strong> {bildirim.bildirim_turu}
          </p>
          <p>
            <strong>Durum:</strong> {bildirim.state ?? "-"}
          </p>
          <p>
            <strong>Aciklama:</strong> {bildirim.aciklama ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/surecler">Surec takibe git</Link>
      </div>
    </section>
  );
}
